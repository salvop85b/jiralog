<?php declare(strict_types=1);

namespace MirkoCesaro\JiraLog\Console\Api\Jira;

class Search extends AbstractApi
{
    /**
     * @param string $jql
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function execute(string $jql, array $options = []): array
    {
        $client = $this->getClient();

        if (empty($options['fields'])) {
            $options['fields'] = ['*all'];
        }

        $response = $client->request(
            'POST',
            '/rest/api/3/search/jql',
            [
                'body' => json_encode(
                    [
                        'jql' => $jql
                    ] + $options
                )
            ]
        );

        $body = $response->getBody()->getContents();

        return json_decode($body, true);
    }

    public function executeLegacy(string $jql, array $options = []): array
    {
        $client = $this->getClient();

        $response = $client->request(
            'POST',
            '/rest/api/2/search',
            [
                'body' => json_encode(
                    [
                        'jql' => $jql
                    ] + $options
                )
            ]
        );

        $body = $response->getBody()->getContents();

        return json_decode($body, true);    }
}