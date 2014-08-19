<?php
namespace Hnw\ListGithubUsers;

//use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Github\HttpClient\CachedHttpClient;
use Github\Client;
use Github\ResultPager;
use Github\HttpClient\Cache\FilesystemCache;

use Hnw\SingleSubcommandConsole\Command\Command;

class ListGithubUsersCommand extends Command
{
    protected function configure()
    {   
        $this->setName('list-github-users')
             ->setDescription("Display GitHub login IDs")
             ->setDefinition(array(
                new InputOption('from', 's', InputOption::VALUE_REQUIRED, 'Start number of the range of GitHub user ID'),
                new InputOption('to', 'e', InputOption::VALUE_REQUIRED, 'Stop number of the range of GitHub user ID'),
                new InputOption('num', null, InputOption::VALUE_REQUIRED, 'Maximum number of login IDs to display'),
                new InputOption('token', 't', InputOption::VALUE_REQUIRED, 'Specify GitHub personal access token'),
                new InputOption('search', null, InputOption::VALUE_REQUIRED, 'Search login name'),
                new InputOption('with-user-id', null, InputOption::VALUE_NONE, 'Display login ID with user ID'),
                new InputOption('with-http-cache', null, InputOption::VALUE_NONE, 'HTTP Access using local cache'),

            ))
            ;
    }

    /**
     *
     *
     * 問題がなければ　true　を返す。
     * 問題があれば \InvalidArgumentException をスローする。
     */
    protected function validate(InputInterface $input)
    {
        $startId = $input->getOption('from');
        $stopId = $input->getOption('to');
        $maxUser  = $input->getOption('num');

        if (isset($startId) && !preg_match('/^[+-]?[0-9]+$/', $startId)) {
            throw new \InvalidArgumentException('"from" number must be integer');
        }
        if (isset($stopId) && !preg_match('/^[+-]?[0-9]+$/', $stopId)) {
            throw new \InvalidArgumentException('"to" number must be integer');
        }
        if (isset($maxUser) && !preg_match('/^[+-]?[0-9]+$/', $maxUser)) {
            throw new \InvalidArgumentException('"num" number must be integer');
        }
        if ($startId < 0) {
            throw new \InvalidArgumentException('"from" number must be positive');
        }
        if ($stopId < 0) {
            throw new \InvalidArgumentException('"to" number must be positive');
        }
        if ($maxUser < 0) {
            throw new \InvalidArgumentException('"num" number must be positive');
        }
        if ($stopId > 0 && $startId >= $stopId) {
           throw new \InvalidArgumentException('"to" number must be greater than "from" number');
        }
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->validate($input);

            $client = $this->getGitHubClient($input);
            $keyword = $input->getOption('search');

            if ($keyword !== null && $keyword !== '') {
                $users = $this->findGitHubUsers($client, $keyword);
            } else {
                $users = $this->getAllGitHubUsers($client, $input->getOption('from'));
            }
            $users = $this->limitNumUsers($input, $users);
            $users = $this->limitMaxUserId($input, $users);
            $this->outputLoginName($users, $input, $output);
        } catch (\Exception $e) {
            if ($output instanceof ConsoleOutputInterface) {
                $err = $output->getErrorOutput();
            } else {
                $err = $output;
            }
            $err->writeln('<error>ERROR: ' . get_class($e) . ": " . $e->getMessage() . '</error>');
        }
    }

    /**
     * GitHub Clientのインスタンスを帰す。
     * もし引数で設定されていればauth tokenを設定する。
     *
     * @param InputInterface $input
     * @return Client
     */
    protected function getGitHubClient(InputInterface $input)
    {
        $httpClient = null;
        if ($input->getOption('with-http-cache')) {
            $httpClient = new CachedHttpClient();
            $httpClient->setCache(
                new FilesystemCache(__DIR__.'/cache/github-api-cache')
            );
        }
        $client = new Client($httpClient);

        $token = $input->getOption('token');
        if ($token) {
            $client->authenticate($token, null, Client::AUTH_URL_TOKEN);
        }
        return $client;
    }

    /**
     * ユーザーのリスト（ジェネレータ）を受け取り、その情報を出力する
     *
     * @param \Iterator $users
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function outputLoginName(\Iterator $users, InputInterface $input, OutputInterface $output)
    {
        $withUserId  = $input->getOption('with-user-id');
        foreach ($users as $user) {
            if ($withUserId) {
                $output->writeln($user['id'].','.$user['login']);
            } else {
                $output->writeln($user['login']);
            }
        }
    }

    protected function getAllGitHubUsers(Client $client, $startId = null)
    {
        $lastId = ($startId <= 1) ? null : ($startId-1);
        return $this->fetchUsersAsGenerator($client, 'all', array($lastId));
    }

    protected function findGitHubUsers(Client $client, $keyword)
    {
        return $this->fetchUsersAsGenerator($client, 'find', array($keyword));
    }

    /**
     * @param Client $client
     * @param string $method
     * @param array $params
     * @return \Generator
     */
    protected function fetchUsersAsGenerator(Client $client, $method, $params)
    {
        $paginator = new ResultPager($client);
        $userApi = $client->api('user');

        $results = $paginator->fetch($userApi, $method, $params);
        while (true) {
            foreach ($results as $result) {
                if (is_array($result) && isset($result[0]) && is_array($result[0])) {
                    foreach ($result as $user) {
                        yield $user;
                    }
                } else {
                    yield $result;
                }
            }
            if (!$paginator->hasNext()) {
                break;
            }
            $results = $paginator->fetchNext();
        }
    }

    /**
     * @param InputInterface $input
     * @param \Iterator $users
     * @return \Iterator
     */
    protected function limitNumUsers(InputInterface $input, \Iterator $users)
    {
        $numUser  = intval($input->getOption('num'));
        if ($numUser) {
            return new \LimitIterator($users, 0, $numUser);
        } else {
            return $users;
        }
    }

    protected function limitMaxUserId(InputInterface $input, \Iterator $users)
    {
        $maxUserId  = intval($input->getOption('to'));
        if ($maxUserId) {
            return $this->takeWhile($users, function($user) use($maxUserId) { return $user['id'] <= $maxUserId; });
        } else {
            return $users;
        }
    }

    protected function takeWhile(\Iterator $users, callable $pred)
    {
        foreach ($users as $user) {
            if (call_user_func($pred, $user)) {
                yield $user;
            } else {
                break;
            }
        }
    }

}
