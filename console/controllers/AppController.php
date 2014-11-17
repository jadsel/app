<?php
/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2014 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace console\controllers;

use dektrium\user\ModelManager;
use dmstr\console\controllers\BaseAppController;
use mikehaertl\shellcommand\Command;
use yii\base\Exception;


/**
 * Task runner command for development.
 * @package console\controllers
 * @author Tobias Munk <tobias@diemeisterei.de>
 */
class AppController extends BaseAppController
{

    public function init()
    {
        try {
            return parent::init(); // TODO: Change the autogenerated stub
        } catch (Exception $e) {
            echo "Warning: " . $e->getMessage() . "\n";
            echo "Some actions may not perform correctly\n\n";
        }
    }

    public $defaultAction = 'version';

    /**
     * Displays application version from git describe
     */
    public function actionVersion()
    {
        echo "Application Version\n";
        $this->execute('git describe');
        echo "\n";
    }

    /**
     * Update application and vendor source code, run database migrations, clear cache
     */
    public function actionUpdate()
    {
        $this->execute("git pull");
        $this->composer("install");
        $this->action('migrate');
        $this->action('cache/flush', 'cache');
    }

    /**
     * Initial application setup
     */
    public function actionSetup()
    {
        $this->action('migrate', ['interactive' => $this->interactive]);
        $this->action('app/setup-admin-user', ['interactive' => $this->interactive]);
        $this->action('app/virtual-host', ['interactive' => $this->interactive]);
    }

    /**
     * Install packages for application testing
     */
    public function actionSetupTests()
    {
        $this->action('migrate', ['db' => 'db_test', 'interactive' => $this->interactive]);

        $this->composer(
            'global require "codeception/codeception:2.0.*" "codeception/specify:*" "codeception/verify:*"'
        );
        $this->composer(
            'require --dev "yiisoft/yii2-coding-standards:2.*" "yiisoft/yii2-codeception:2.*" "yiisoft/yii2-faker:2.*"'
        );

        $this->execute('codecept build -c tests/codeception/backend');
        $this->execute('codecept build -c tests/codeception/frontend');
        $this->execute('codecept build -c tests/codeception/common');
        $this->execute('codecept build -c tests/codeception/console');
    }

    /**
     * Run all test suites with web-server from PHP executable
     */
    public function actionRunTests()
    {
        echo "Note! You can tear down the test-server with `killall php`\n";
        if ($this->confirm("Start testing?", true)) {
            $this->execute('php -S localhost:8042 > /dev/null 2>&1 &');

            $commands[] = 'codecept run -c tests/codeception/backend';
            $commands[] = 'codecept run -c tests/codeception/frontend';
            $commands[] = 'codecept run -c tests/codeception/common';
            $commands[] = 'codecept run -c tests/codeception/console';

            $hasError = false;
            foreach ($commands AS $command) {
                $cmd = new Command($command);
                if ($cmd->execute()) {
                    echo $cmd->getOutput();
                } else {
                    echo $cmd->getOutput();
                    echo $cmd->getStdErr();
                    echo $cmd->getError();
                    $hasError = true;
                }
                echo "\n";
            }
            if ($hasError) {
                return 1;
            } else {
                return 0;
            }
        }
    }

    /**
     * Clear $app/web/assets folder, null clears all assets in frontend and backend
     *
     * @param frontend|backend|null $app
     */
    public function actionClearAssets($app = null)
    {
        $frontendAssets = \Yii::getAlias('@frontend/web/assets');
        $backendAssets  = \Yii::getAlias('@backend/web/assets');

        // Matches from 7-8 char folder names, the 8. char is optional
        $matchRegex     = '"^[a-z0-9][a-z0-9][a-z0-9][a-z0-9][a-z0-9][a-z0-9][a-z0-9]\?[a-z0-9]$"';

        // create $cmd command
        switch ($app) {
            case null :
                $app = "frontend & backend";
                $cmd = 'cd "' . $frontendAssets . '" && ls | grep -e ' . $matchRegex . ' | xargs rm -rf ';
                $cmd .= ' && cd "' . $backendAssets . '" && ls | grep -e ' . $matchRegex . ' | xargs rm -rf ';
                break;
            case 'frontend':
            case 'backend' :

                // Set $assetFolder depending on $app param
                if ($app === 'frontend') {
                    $assetFolder = $frontendAssets;
                } elseif ($app === 'backend') {
                    $assetFolder = $backendAssets;
                }
                $cmd = 'cd "' . $assetFolder . '" && ls | grep -e "^[a-z0-9][a-z0-9][a-z0-9][a-z0-9][a-z0-9][a-z0-9][a-z0-9]\?[a-z0-9]$" | xargs rm -rf ';
                break;
        }

        // Set command
        $command = new Command($cmd);

        // Try to execute $command
        if ($command->execute()) {
            echo "\nOK - " . $app . " assets has been deleted." . "\n\n";
        } else {
            echo "\n" . $command->getError() . "\n";
            echo $command->getStdErr();
        }
    }

    /**
     * Install packages for documentation rendering
     */
    public function actionSetupDocs()
    {
        $this->composer(
            'require --dev "cebe/markdown-latex:dev-master" "yiisoft/yii2-apidoc:2.*"'
        );
    }

    /**
     * Setup admin user (create, update password, confirm)
     */
    public function actionSetupAdminUser()
    {
        $mgr   = new ModelManager;
        $admin = $mgr->findUserByUsername('admin');
        if ($admin === null) {
            $email = $this->prompt(
                'E-Mail for application admin user:',
                ['default' => getenv('APP_ADMIN_EMAIL')]
            );
            $this->action('user/create', [$email, 'admin']);
            $password = $this->prompt(
                'Password for application admin user:',
                ['default' => getenv('APP_ADMIN_PASSWORD')]
            );
        } else {
            $password = $this->prompt(
                'Update password for application admin user (leave empty to skip):'
            );
        }
        if ($password) {
            $this->action('user/password', ['admin', $password]);
        }
        sleep(1); // confirmation may not succeed without a short pause
        $this->action('user/confirm', ['admin']);
    }

    /**
     * Generate application and required vendor documentation
     */
    public function actionGenerateDocs()
    {
        if ($this->confirm('Regenerate documentation files into ./docs-html', true)) {
            $this->execute('vendor/bin/apidoc guide --interactive=0 docs docs-html');
            $this->execute(
                'vendor/bin/apidoc api --interactive=0 --exclude=runtime/,tests/ backend,common,console,frontend docs-html'
            );
            $this->execute('vendor/bin/apidoc guide --interactive=0 docs docs-html');
        }
    }

    /**
     * Setup vhost with virtualhost.sh script
     */
    public function actionVirtualHost()
    {
        if (`which virtualhost.sh`) {
            echo "\n";
            $frontendName = $this->prompt('"Frontend" Domain-name (example: myproject.com.local, leave empty to skip)');
            if ($frontendName) {
                $this->execute(
                    'virtualhost.sh ' . $frontendName . ' ' . \Yii::getAlias('@frontend') . DIRECTORY_SEPARATOR . "web"
                );
                echo "\n";
                $defaultBackendName = 'admin.' . $frontendName;
                $backendName        = $this->prompt(
                    '"Backend" Domain-name',
                    ['default' => $defaultBackendName]
                );
                if ($backendName) {
                    $this->execute(
                        'virtualhost.sh ' . $backendName . ' ' . \Yii::getAlias(
                            '@backend'
                        ) . DIRECTORY_SEPARATOR . "web"
                    );
                }
            }
        } else {
            echo "Command virtualhost.sh not found, skipping.\n";
        }
    }

} 