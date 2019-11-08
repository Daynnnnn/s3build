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
                            {--docker-image= : Docker image to build project in}
                            {--out-dir= : Folder built files are located}';

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
            $environmentVariables;

            foreach ($_ENV as $key => $value) {
                if (substr($key, 0, 11 ) === "bamboo_docker_") {
                    $dockerVariables[substr($key, 13)] = $value;
                } elseif (substr($key, 0, 11 ) === "bamboo_") {
                    $bambooVariables[substr($key, 7)] = $value;
                }
            }
            if (count($dockerVariables) > 0) {
                // Cast each environment variable as a string
                foreach ($dockerVariables as $key => $value) {
                    $dockerVariables[$key] = (string) $value;
                }
            }
            if (count($bambooVariables) > 0) {
                // Cast each environment variable as a string
                foreach ($bambooVariables as $key => $value) {
                    $bambooVariables[$key] = (string) $value;
                }
            }
        }   

        if (null == $this->option('app')) { echo 'Please set the --app option'; exit(1);}
        if (null == $this->option('branch')) { echo 'Please set the --branch option'; exit(1);}
        if (null == $this->option('environment')) { echo 'Please set the --environment option'; exit(1);}
        if (null == $this->option('AWS_ID')) { echo 'Please set the --AWS_ID option'; exit(1);}
        if (null == $this->option('AWS_SECRET')) { echo 'Please set the --AWS_SECRET option'; exit(1);}
        if (null == $this->option('docker-image')) {
            if (!isset($environmentVariables['docker-image'])) {
                echo 'Using default image (node:10)';
                $Image = 'node:10';
            } else {
                $Image = $environmentVariables['docker-image'];
            }
        } else {
            $Image = $this->option('docker-image');
        }
        if (null == $this->option('out-dir')) {
            if (!isset($environmentVariables['out-dir'])) {
                echo 'Using default out directory (dist)';
                $outDirectory = 'dist';
            } else {
                $outDirectory = $environmentVariables['out-dir'];
            }
        } else {
            $outDirectory = $this->option('out-dir');
        }

        // Set S3 bucket Variables
        $Bucket = 's3build-' . $this->option('app') . '-' . $this->option('branch') . '-' . $this->option('environment');
        $Policy = file_get_contents(__DIR__.'/../../config/PublicBucket.json');
        $Policy = str_replace('{{ bucket }}', $Bucket, $Policy);

        // Define S3 Client
        $s3Client = new S3Client([
            'region' => 'eu-west-1',
            'version' => '2006-03-01',
            'credentials' => [
                'key'    => $this->option('AWS_ID'),
                'secret' => $this->option('AWS_SECRET'),
            ],
        ]);

        if (null == $s3Client->headBucket( array('Bucket' => $Bucket))) {
            // Create S3 bucket
            try {
                $result = $s3Client->createBucket([
                    'Bucket' => $Bucket,
                ]);
            } catch (AwsException $e) {
                echo $e->getMessage();
                echo "\n";
            }
        //}

        if ($this->option('no-build') !== 'true' ) {
            if (isset($environmentVariables)) {
    
                $env = array();
                foreach ($dockerVariables as $key => $value) {
                    array_push($env, $key.'='.$value);
                }

                $command = 'docker run --volume ' . getcwd() . ':/data --workdir /data --rm -e ' . implode(' -e ', $env) . ' ' . $Image . ' make';
                shell_exec($command);
    
            } else {
    
                $command = 'docker run --volume ' . getcwd() . ':/data --workdir /data --rm ' . $Image . ' make';
                shell_exec($command);
            }
        }
        // Upload site
        $s3Client->uploadDirectory($outDirectory.'/', $Bucket);

        $result = $s3Client->putBucketWebsite([
            'Bucket' => $Bucket,
            'WebsiteConfiguration' => [
                'ErrorDocument' => [
                    'Key' => '200.html',
                ],
                'IndexDocument' => [
                    'Suffix' => 'index.html',
                ],
            ],
        ]);

        // Sets public bucket policy
        try {
            $resp = $s3Client->putBucketPolicy([
                'Bucket' => $Bucket,
                'Policy' => $Policy,
            ]);
            echo "Succeed in put a policy on bucket: " . $Bucket . "\n";
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
