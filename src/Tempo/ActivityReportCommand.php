<?php declare(strict_types=1);

namespace MirkoCesaro\JiraLog\Console\Tempo;

use Dotenv\Dotenv;
use MirkoCesaro\JiraLog\Console\Api\Jira\Search;
use MirkoCesaro\JiraLog\Console\Api\Jira\User;
use MirkoCesaro\JiraLog\Console\Api\Tempo\Account;
use MirkoCesaro\JiraLog\Console\Api\Tempo\AccountLinks;
use MirkoCesaro\JiraLog\Console\Api\Tempo\Periods;
use MirkoCesaro\JiraLog\Console\Api\Tempo\WorkAttributes;
use MirkoCesaro\JiraLog\Console\Api\Tempo\Worklog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityReportCommand extends Command
{
    /** Issue Key,Issue summary,Hours,Work date,User Account ID,Full name,Tempo Team,Period,Account Key,Account Name,Account Lead ID,Account Category,Account Customer,Activity Name,Component,All Components,Version Name,Issue Type,Issue Status,Project Key,Project Name,Epic,Epic Link,Work Description,Parent Key,Reporter ID,External Hours,Billed Hours,Issue Original Estimate,Issue Remaining Estimate,External Jira Key,External Jira Epic,External Jira Id,Activity,Date created,Date updated */

    protected static $defaultName = 'tempo:activity-report';
    protected Dotenv $dotEnv;

    protected array $issues;
    protected array $account;
    protected array $accounts;
    protected array $users;

    protected array $workAttributes;

    protected array $projectAccounts = [];

    public function __construct(string $name = null)
    {
        parent::__construct($name);

        $this->dotEnv = Dotenv::createImmutable(
            __DIR__ .
            DIRECTORY_SEPARATOR . '..' .
            DIRECTORY_SEPARATOR . '..'
        );

        $this->dotEnv->load();
        $this->dotEnv->required("TEMPO_ENDPOINT");
        $this->dotEnv->required("TOKEN");
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Report By Period')
            ->addArgument('date', InputArgument::OPTIONAL, 'Start Date', date('Y-m-d'))
            ->addArgument('end_date', InputArgument::OPTIONAL, 'End Date', date('Y-m-d'));
    }

    protected function getJiraSearch(): Search
    {
        return new Search([
            'base_url' => $_SERVER['JIRA_ENDPOINT'],
            'username' => $_SERVER['JIRA_EMAIL'],
            'password' => $_SERVER['JIRA_TOKEN']
        ]);
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $worklogApi = new Worklog($_SERVER['TEMPO_ENDPOINT'], $_SERVER['TOKEN']);
        $accountApi = new Account($_SERVER['TEMPO_ENDPOINT'], $_SERVER['TOKEN']);
        $accountLinksApi = new AccountLinks($_SERVER['TEMPO_ENDPOINT'], $_SERVER['TOKEN']);
        $workAttributesApi = new WorkAttributes($_SERVER['TEMPO_ENDPOINT'], $_SERVER['TOKEN']);
        $periodsApi = new Periods($_SERVER['TEMPO_ENDPOINT'], $_SERVER['TOKEN']);

        $output->writeln("Estrazione Accounts...");
        $accounts = $accountApi->get(['offset' => 0,'limit' => 1000])['results'];
        foreach($accounts as $account) {
            $this->accounts[$account['id']] = $account;
        }

        $output->writeln("Estrazione Work Attributes...");
        $workAttributes = $workAttributesApi->get();
        foreach ($workAttributes['results'] as $workAttribute) {
            $values = array_combine($workAttribute['values'], $workAttribute['names']);
            $this->workAttributes[$workAttribute['key']] = $values;
        }

        $options = [
            'from' => $input->getArgument("date"),
            'to' => $input->getArgument("end_date"),
            'limit' => 1000
        ];

        $results = [];
        $page = 1;
        $this->account = $accountApi->getAccount(44);

        do {

            $output->writeln(sprintf("[Pagina %s] - Estrazione in corso...", $page));

            $body = $worklogApi->get($options);

            $results = array_merge($results, $body['results']);

            if (empty($body['metadata']['next'])) {
                break;
            }

            $options['offset'] = $body['metadata']['offset'] + $body['metadata']['limit'];
            $page++;

        } while (1);

        $this->issues = array_reduce($results, function($issues, $result) {
            if(!in_array($result['issue']['id'], $issues)) {
                $issues[] = $result['issue']['id'];
            }

            return $issues;

        }, []);

        $epics = [];
        foreach($this->getIssuesByKey($this->issues) as $issue) {

            if(
                !empty($issue['fields']['parent']['id']) &&
                empty($this->issues[$issue['fields']['parent']['id']])
            ) {
                $epics[] = $issue['fields']['parent']['id'];
            }
            if(!empty($issue['fields']['customfield_10122'])) {
                $issue['account'] = $this->accounts[$issue['fields']['customfield_10122']['id']];

            }
            $this->issues[$issue['id']] = $issue;

        }

        foreach($this->getIssuesByKey(array_unique($epics)) as $issue) {

            if(!empty($issue['fields']['customfield_10122'])) {
                $issue['account'] = $this->accounts[$issue['fields']['customfield_10122']['id']];

            }
            $this->issues[$issue['id']] = $issue;
        }

        $results = (array_map([$this, 'mapResults'], $results));

        $h = fopen("report.csv", "w");

        fputcsv($h, array_keys($results[0]));
        foreach($results as $result) {
            fputcsv($h, $result);
        }
        fclose($h);



        return Command::SUCCESS;
    }

    protected function getIssuesByKey(array $keys): array
    {
        $query = sprintf("id in (%s)", implode(',', $keys));
        $options = ['maxResults' => 100, 'startAt' => 0];
        $results = [];
        do {
            echo sprintf("Recupero Issues...\n");

            $response = $this->getJiraSearch()->execute($query, $options);
            $results = array_merge($results, $response['issues']);
            $options['startAt']+= $response['maxResults'];

        } while(count($results) < $response['total'] and $response['total'] > $options['startAt']);

        return $results;
    }

    protected function getIssue(int $issueId): array
    {
        if(empty($this->issues[$issueId])) {

            $api = new Search([
                'base_url' => $_SERVER['JIRA_ENDPOINT'],
                'username' => $_SERVER['JIRA_EMAIL'],
                'password' => $_SERVER['JIRA_TOKEN']
            ]);

            $response = $api->execute("id = $issueId");

            $this->issues[$issueId] = $response['issues'][0];

            file_put_contents("issues.json", json_encode($this->issues, JSON_PRETTY_PRINT));

        }

        return $this->issues[$issueId];
    }

    protected function getUser(string $userId): array
    {
        if(empty($this->users[$userId])) {

            $api = new User([
                'base_url' => $_SERVER['JIRA_ENDPOINT'],
                'username' => $_SERVER['JIRA_EMAIL'],
                'password' => $_SERVER['JIRA_TOKEN']
            ]);

            echo sprintf("Recupero dati utente %s\n", $userId);

            $response = $api->getByAccountId($userId);

            $this->users[$userId] = $response;

            file_put_contents("users.json", json_encode($this->users, JSON_PRETTY_PRINT));

        }

        return $this->users[$userId];
    }

    protected function mapResults(array $result): array
    {
        $issue = $this->getIssue($result['issue']['id']);
        $parentIssue = empty($issue['fields']['parent']['id']) ? [] :  $this->getIssue((int)$issue['fields']['parent']['id']);
        $user = $this->getUser($result['author']['accountId']);

        $attributes = [];
        foreach($result['attributes']['values'] as $attribute) {
            $attributes[$attribute['key']] = $this->workAttributes[$attribute['key']][$attribute['value']] ?? $attribute['value'];
        }

        $account = $issue['account'] ?? null;
        if(empty($account)) {
            echo sprintf("Attenzione: Account non trovato per la issue: %s\n", $issue['key']);
        }

        $parsedResult = [
            "Issue Key " => $issue['key'],
            "Issue summary" => $issue['fields']['summary'],
            "Hours" => number_format($result['timeSpentSeconds'] / 3600, 4,),
            "Work date" => \DateTime::createFromFormat("Y-m-d", $result['startDate'])->format("d/m/Y"),
            "User Account ID" => $result['author']['accountId'],
            "Full name" => $user['displayName'],
            "Tempo Team" => '',
            "Program" => '',
            "Program Manager ID" => '',
            "Period" => '',
            "Account Key" => $account['key'],
            "Account Name" => $account['name'],
            "Account Status" => '',
            "Account Lead ID" => $account['lead']['accountId'],
            "Account Category" => $account['category']['name'],
            "Account Category Type" => '',
            "Account Customer" => '',
            "Account Contact ID" => '',
            "Account Contact External" => '',
            "Activity Name" => $issue['fields']['project']['name'],
            "Component" => '',
            "All Components" => '',
            "Version Name" => '',
            "Issue Type" => $issue['fields']['issuetype']['name'],
            "Issue Status" => $issue['fields']['status']['name'],
            "Priority" => '',
            "Project Key" => $issue['fields']['project']['key'],
            "Project Name" => $issue['fields']['project']['name'],
            "Epic" => '',
            "Epic Link" => $parentIssue['fields']['customfield_10011'] ?? '-',
            "Work Description" => $result['description'],
            "Parent Key" => $parentIssue['key'] ?? '-',
            "Reporter ID" => $issue['fields']['reporter']['accountId'],
            "Assignee ID" => '',
            "External Hours" => '',
            "Billed Hours" => number_format($result['billableSeconds'] / 3600, 4) ,
            "Issue Original Estimate" => $issue['fields']['timeoriginalestimate'] / 3600,
            "Issue Remaining Estimate" => $issue['fields']['timeestimate'] / 3600,
            "External Jira Key" => $issue['fields']['customfield_10118'] ?? null,
            "External Jira Epic" => $issue['fields']['customfield_10123'] ?? null,
            "External Jira Id" => $issue['fields']['customfield_10124'] ?? null,
            "Activity" => $attributes["_Activity_"],
            "Date created" => (new \DateTime($result['createdAt']))->setTimezone(new \DateTimeZone("Europe/Rome"))->format('Y-m-d H:i'),
            "Date updated" => (new \DateTime($result['updatedAt']))->setTimezone(new \DateTimeZone("Europe/Rome"))->format('Y-m-d H:i'),
        ];

        return $parsedResult;
    }
}