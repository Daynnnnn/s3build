<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Miloske85\php_cli_table\Table as CliTable;
use App\Providers\AwsProvider;

class Build extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'build 
                            {--app= : Name of App}
                            {--build= : Build number}
                            {--branch= : App Branch}
                            {--environment= : App Environment}
                            {--no-build= : If true, docker build won\'t run}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Deploy a Static Website To An S3 Bucket';

    protected $environmentVariables = array();

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // Set environment variables
        if (isset($_ENV) && count($_ENV) > 1) {
            // Add deploy_environment variables from bamboo_env_ keys
            foreach ($_ENV as $key => $value) {
                if (substr($key, 0, 14 ) === 'bamboo_docker_') {
                    $dockerVariables[substr($key, 14)] = $value;
                } elseif (substr($key, 0, 7 ) === 'bamboo_') {
                    $this->environmentVariables[substr($key, 7)] = $value;
                }
            }

            if (isset($dockerVariables) && count($dockerVariables) > 0) {
                // Cast each environment variable as a string
                foreach ($dockerVariables as $key => $value) {
                    $dockerVariables[$key] = (string) $value;
                }
            }

            if (count($this->environmentVariables) > 0) {
                // Cast each environment variable as a string
                foreach ($this->environmentVariables as $key => $value) {
                    $this->environmentVariables[$key] = (string) $value;
                }
            }
        }

        // Error on missing needed values
        if (null == $this->option('app')) { echo 'Please set the --app option'; exit(1);} else {$this->environmentVariables['app'] = $this->option('app');}
        if (null == $this->option('branch')) { echo 'Please set the --branch option'; exit(1);} else {$this->environmentVariables['branch'] = $this->option('branch');}
        if (null == $this->option('environment')) { echo 'Please set the --environment option'; exit(1);} else {$this->environmentVariables['environment'] = $this->option('environment');}
        if (!isset($this->environmentVariables['AWS_ID'])) { echo 'Please set the --AWS_ID option'; exit(1);}
        if (!isset($this->environmentVariables['AWS_SECRET'])) { echo 'Please set the --AWS_SECRET option'; exit(1);}

        // Set default values on optional values
        if (!isset($this->environmentVariables['IMAGE'])) {
            $this->environmentVariables['IMAGE'] = 'node:10';
        }
        if (!isset($this->environmentVariables['OUT_DIR'])) {
            $this->environmentVariables['OUT_DIR'] = 'dist';
        }
        if (!isset($this->environmentVariables['INDEX_FILE'])) {
            $this->environmentVariables['INDEX_FILE'] = 'index.html';
        }
        if (!isset($this->environmentVariables['ERROR_FILE'])) {
            $this->environmentVariables['ERROR_FILE'] = 'error.html';
        }
        if (!isset($this->environmentVariables['BUCKET_NAME'])) {
            $this->environmentVariables['BUCKET_NAME'] = 's3build-shared';
        }
        if (!isset($this->environmentVariables['BUCKET_REGION'])) {
            $this->environmentVariables['BUCKET_REGION'] = 'eu-west-1';
        }
        if (!isset($this->environmentVariables['COMMAND'])) {
            $this->environmentVariables['COMMAND'] = 'make';
        }

        // Set S3 bucket Variables
        $this->environmentVariables['BUCKET_NAME_POSTFIX'] = '/' . $this->option('app') . '-' . $this->option('branch') . '-' . $this->option('environment') . '-' . $this->option('build');

        $Policy = [
           'Version' => '2008-10-17', 
           'Statement' => [
                 [
                    'Sid' => 'AllowPublicRead', 
                    'Effect' => 'Allow', 
                    'Principal' => '*', 
                    'Action' => 's3:GetObject', 
                    'Resource' => 'arn:aws:s3:::' . $this->environmentVariables['BUCKET_NAME'] . '/*' 
                 ] 
              ] 
        ]; 

        $bucketGenOutput = array();
        $push = array($this->environmentVariables['BUCKET_NAME'], '✓');

        // Define S3 Client
        $s3Client = new S3Client([
            'region' => 'eu-west-1',
            'version' => '2006-03-01',
            'credentials' => [
                'key'    => $this->environmentVariables['AWS_ID'],
                'secret' => $this->environmentVariables['AWS_SECRET'],
            ],
        ]); 

        if (AwsProvider::checkForBucket($s3Client, $this->environmentVariables['BUCKET_NAME']) == false) {
            try {
                // Create S3 bucket
                $result = $s3Client->createBucket([
                    'Bucket' => $this->environmentVariables['BUCKET_NAME'],
                ]);
            } catch (AwsException $e) {
                $push = array($this->environmentVariables['BUCKET_NAME'], 'x');
                $BucketGenError = $e->getMessage();
            }
        }

        array_push($bucketGenOutput, $push);

        $this->table(
            array('S3 Bucket', 'Status'),
            $bucketGenOutput
        );

        if ($bucketGenOutput['0']['1'] == 'x') {

            $this->table(
                array('S3 Bucket Creation Failed'),
                AwsProvider::shortError($BucketGenError)
            );
            exit(1);
        }

        if ($this->option('no-build') !== 'true' ) {
            if (isset($dockerVariables)) {
    
                $env = array();

                foreach ($dockerVariables as $key => $value) {
                    array_push($env, $key.'='.$value);
                }

                $command = 'docker run --volume ' . getcwd() . ':/data --workdir /data --rm -e ' . implode(' -e ', $env) . ' ' . $this->environmentVariables['IMAGE'] . ' ' . $this->environmentVariables['COMMAND'];
                $output = shell_exec($command);
    
            } else {
    
                $command = 'docker run --volume ' . getcwd() . ':/data --workdir /data --rm ' . $this->environmentVariables['IMAGE'] . ' ' . $this->environmentVariables['COMMAND'];
                $output = shell_exec($command);
            }
        }

        // Upload site
        $s3Client->uploadDirectory($this->environmentVariables['OUT_DIR'].'/', $this->environmentVariables['BUCKET_NAME'] . '/' . $this->option('build'));

        // Set bucket to server static site
        AwsProvider::setS3Site($s3Client, $this->environmentVariables['BUCKET_NAME'], $this->environmentVariables['INDEX_FILE'], $this->environmentVariables['ERROR_FILE']);

        // Sets public read bucket policy
        $s3Client->putBucketPolicy(['Bucket' => $this->environmentVariables['BUCKET_NAME'],'Policy' => json_encode($Policy),]);

        $this->table(
            array('Domains'),
            array(array('https://' . $this->environmentVariables['BUCKET_NAME']. '.s3-' . $this->environmentVariables['BUCKET_REGION'] . '.amazonaws.com/index.html'))
        );

        echo PHP_EOL . PHP_EOL . $output . PHP_EOL . PHP_EOL;

        $this->table(
            array('Domains'),
            array(array('https://' . $this->environmentVariables['BUCKET_NAME']. '.s3-' . $this->environmentVariables['BUCKET_REGION'] . '.amazonaws.com' . $this->environmentVariables['BUCKET_NAME_POSTFIX'] . '/' . $this->environmentVariables['INDEX_FILE']))
        );
    }

    /**
     * Define the command's schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    public function schedule(Schedule $schedule)
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
