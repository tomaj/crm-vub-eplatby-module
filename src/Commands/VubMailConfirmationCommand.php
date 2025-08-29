<?php

namespace Crm\VubEplatbyModule\Commands;

use Crm\ApplicationModule\Models\Config\ApplicationConfig;
use Crm\PaymentsModule\Models\MailConfirmation\EmailInterface;
use Crm\PaymentsModule\Models\MailConfirmation\MailDownloaderInterface;
use Crm\PaymentsModule\Models\MailConfirmation\MailProcessor;
use Tomaj\BankMailsParser\Parser\Vub\VubMailParser;
use Nette\Utils\FileSystem;
use Nette\Utils\Random;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tomaj\ImapMailDownloader\MailCriteria;
use Tracy\Debugger;
use Tracy\ILogger;

class VubMailConfirmationCommand extends Command
{
    private OutputInterface $output;

    private string $zipPassword;

    public function __construct(
        private string $tempDir,
        private MailDownloaderInterface $mailDownloader,
        private MailProcessor $mailProcessor,
        private ApplicationConfig $applicationConfig,
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('vub:mail_confirmation')
            ->setDescription('Check notification emails and confirm payments based on VUB emails');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->zipPassword = $this->applicationConfig->get('vub_zip_password');

        $connectionOptions = [
            'imapHost' => $this->applicationConfig->get('vub_confirmation_host'),
            'imapPort' => $this->applicationConfig->get('vub_confirmation_port'),
            'username' => $this->applicationConfig->get('vub_confirmation_username'),
            'password' => $this->applicationConfig->get('vub_confirmation_password'),
            'processedFolder' => $this->applicationConfig->get('vub_confirmation_processed_folder'),
        ];

        $criteria = new MailCriteria();
        $criteria->setFrom('nonstopbanking@vub.sk');
        $criteria->setUnseen(true);
        $connectionOptions['criteria'] = $criteria;

        $this->mailDownloader->download($connectionOptions, function (EmailInterface $email) {
            $parser = new VubMailParser();

            $body = $this->getAttachmentBody($email);
            $mailContent = $parser->parse(quoted_printable_decode($body));
            if ($mailContent !== null && $body) {
                $this->mailProcessor->processMail($mailContent, $this->output);
            }
        });

        return Command::SUCCESS;
    }

    private function validateAttachment(EmailInterface $email): bool
    {
        $attachments = $email->getAttachments();
        if (empty($attachments)) {
            $vs = '';
            $result = [];
            $body = $email->getBody();
            $vsPattern = '/č.ob. (\d{10})/m';

            $res = preg_match($vsPattern, $body, $result);
            if ($res) {
                $vs = $result[1];
            }

            Debugger::log(
                'missing VUB mail attachment for payment with variable symbol: ' . $vs,
                ILogger::WARNING,
            );
            return false;
        }
        return true;
    }

    private function getAttachmentBody(EmailInterface $email): bool|string
    {
        if (!$this->validateAttachment($email)) {
            return false;
        }

        $filePath = $this->tempDir . DIRECTORY_SEPARATOR . 'payments-mail-parser/' . Random::generate() . '.zip';

        FileSystem::write($filePath, array_values($email->getAttachments())[0]['attachment']);

        $zip = new \ZipArchive();
        $zip->open($filePath);
        $zip->setPassword($this->zipPassword);

        $contents = $zip->getFromIndex(0);
        if (!$contents) {
            throw new \Exception('error opening VUB confirmation e-mail attachment: probably bad password in CRM configuration)');
        }

        $contents = iconv("UTF-8", "ISO-8859-1//IGNORE", $contents);

        FileSystem::delete($filePath);

        return $contents;
    }
}
