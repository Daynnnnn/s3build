<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class Build extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'build 
                            {--app= : Name of app}
                            {--branch= : Branch}
                            {--environment= : Environment}
                            {--AWS_ID= : AWS Access ID }
                            {--AWS_SECRET= : AWS Secret Access Key}
                            {--no-build= : If true, docker build won\'t run}
                            {--out-dir= : Folder built files are placed}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Deploy a Static Website To An S3 Bucket';

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
                if (substr($key, 0, 14 ) === "bamboo_docker_") {
                    $dockerVariables[substr($key, 14)] = $value;
                } elseif (substr($key, 0, 7 ) === "bamboo_") {
                    $environmentVariables[substr($key, 7)] = $value;
                }
            }

            if (isset($dockerVariables) && count($dockerVariables) > 0) {
                // Cast each environment variable as a string
                foreach ($dockerVariables as $key => $value) {
                    $dockerVariables[$key] = (string) $value;
                }
            }

            if (count($environmentVariables) > 0) {
                // Cast each environment variable as a string
                foreach ($environmentVariables as $key => $value) {
                    $environmentVariables[$key] = (string) $value;
                }
            }
        }   

        // Error on missing needed values
        if (null == $this->option('app')) { echo 'Please set the --app option'; exit(1);} else {$environmentVariables['app'] = $this->option('app');}
        if (null == $this->option('branch')) { echo 'Please set the --branch option'; exit(1);} else {$environmentVariables['branch'] = $this->option('branch');}
        if (null == $this->option('environment')) { echo 'Please set the --environment option'; exit(1);} else {$environmentVariables['environment'] = $this->option('environment');}
        if (!isset($environmentVariables['AWS_ID'])) { echo 'Please set the --AWS_ID option'; exit(1);}
        if (!isset($environmentVariables['AWS_SECRET'])) { echo 'Please set the --AWS_SECRET option'; exit(1);}

        // Set default values on optional values
        if (!isset($environmentVariables['IMAGE'])) {
            $environmentVariables['IMAGE'] = 'node:10';
        }
        if (!isset($environmentVariables['OUT_DIR'])) {
            $environmentVariables['OUT_DIR'] = 'dist';
        }
        if (!isset($environmentVariables['INDEX_FILE'])) {
            $environmentVariables['INDEX_FILE'] = 'index.html';
        }
        if (!isset($environmentVariables['ERROR_FILE'])) {
            $environmentVariables['ERROR_FILE'] = 'error.html';
        }
        if (!isset($environmentVariables['BUCKET_NAME'])) {
            $environmentVariables['BUCKET_NAME'] = 's3build-' . $environmentVariables['app'] . '-' . $environmentVariables['branch'] . '-' . $environmentVariables['environment'];
        }
        if (!isset($environmentVariables['COMMAND'])) {
            $environmentVariables['COMMAND'] = 'make';
        }

        // Set S3 bucket Variables
        $Policy = file_get_contents(__DIR__.'/../../config/PublicBucket.json');
        $Policy = str_replace('{{ bucket }}', $environmentVariables['BUCKET_NAME'], $Policy);

        // Define S3 Client
        $s3Client = new S3Client([
            'region' => 'eu-west-1',
            'version' => '2006-03-01',
            'credentials' => [
                'key'    => $environmentVariables['AWS_ID'],
                'secret' => $environmentVariables['AWS_SECRET'],
            ],
        ]);
ini_set('memory_limit', '-1');
        //if (null == $s3Client->headBucket( array('Bucket' => $environmentVariables['BUCKET_NAME']))) {
            // Create S3 bucket
            try {
                $result = $s3Client->createBucket([
                    'Bucket' => $environmentVariables['BUCKET_NAME'],
                ]);
            } catch (AwsException $e) {
                echo $e->getMessage();
            }
        //}

        if ($this->option('no-build') !== 'true' ) {
            if (isset($dockerVariables)) {
    
                $env = array();

                foreach ($dockerVariables as $key => $value) {
                    array_push($env, $key.'='.$value);
                }

                $command = 'docker run --volume ' . getcwd() . ':/data --workdir /data --rm -e ' . implode(' -e ', $env) . ' ' . $environmentVariables['IMAGE'] . ' ' . $environmentVariables['COMMAND'];
                print_r($command);
                exit(0);
    
            } else {
    
                $command = 'docker run --volume ' . getcwd() . ':/data --workdir /data --rm ' . $environmentVariables['IMAGE'] . ' ' . $environmentVariables['COMMAND'];
                print_r($command);
                exit(0);
            }
        }

        // Upload site
        $s3Client->uploadDirectory($environmentVariables['OUT_DIR'].'/', $environmentVariables['BUCKET_NAME']);

        $result = $s3Client->putBucketWebsite([
            'Bucket' => $environmentVariables['BUCKET_NAME'],
            'WebsiteConfiguration' => [
                'ErrorDocument' => [
                    'Key' => $environmentVariables['ERROR_FILE'],
                ],
                'IndexDocument' => [
                    'Suffix' => $environmentVariables['INDEX_FILE'],
                ],
            ],
        ]);

        // Sets public bucket policy
        try {
            $resp = $s3Client->putBucketPolicy([
                'Bucket' => $environmentVariables['BUCKET_NAME'],
                'Policy' => $Policy,
            ]);
            echo "Succeed in put a policy on bucket: " . $environmentVariables['BUCKET_NAME'] . "\n";
        } catch (AwsException $e) {
            // Display error message
            echo $e->getMessage();
            echo "\n";
        }
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
