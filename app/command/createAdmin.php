<?php
declare(strict_types=1);

namespace app\command;

use app\controller\AzureList;
use app\controller\Tools;
use app\model\User;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

class createAdmin extends Command
{
    protected function configure()
    {
        // 指令配置
        $this->setName('createAdmin')
            ->addOption('email', null, Option::VALUE_REQUIRED, 'login email')
            ->addOption('passwd', null, Option::VALUE_REQUIRED, 'login passwd')
            ->setDescription('Create administrator account');
    }

    protected function execute(Input $input, Output $output)
    {
        $email_option = $input->getOption('email');
        $passwd_option = $input->getOption('passwd');

        if (!is_string($email_option) || trim($email_option) === '') {
            $output->writeln("<error>Please set a login email.</error>");
            return 1;
        }

        if (!is_string($passwd_option) || trim($passwd_option) === '') {
            $output->writeln("<error>Please set a login password.</error>");
            return 1;
        }

        $email = trim($email_option);
        $passwd = trim($passwd_option);

        if (!Tools::emailCheck($email)) {
            $output->writeln("<error>E-mail format is incorrect.</error>");
            return 1;
        }

        $user = new User();
        $user->email = $email;
        $user->passwd = Tools::encryption($passwd);
        $user->status = 1;
        $user->is_admin = 1;
        $user->personalise = AzureList::defaultPersonalise();
        $user->created_at = time();
        $user->updated_at = time();
        $user->save();

        $output->writeln("<info>An administrator account has been created.</info>");
        return 0;
    }
}
