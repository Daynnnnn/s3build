<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AwsProvider extends ServiceProvider
{

    public static function checkForBucket($s3Client,$BucketName) {
        $buckets = $s3Client->listBuckets();
        $bucketExists = false;

        foreach ($buckets['Buckets'] as $bucket) {
            if ($bucket['Name'] === $BucketName) {
                $bucketExists = true;
            }
        }

        if ($bucketExists == true) {
            return true;
        } else {
            return false;
        }
    }

    public static function shortError($BucketGenError) {
            if (strrpos($BucketGenError, '409 Conflict') != FALSE){
                $BucketGenError = 'There is already a bucket with this name not in your account.';
                return array(array($BucketGenError));
            }
    }

    public static function setS3Site($s3Client,$BucketName,$IndexFile, $ErrorFile) {
        $result = $s3Client->putBucketWebsite([
            'Bucket' => $BucketName,
            'WebsiteConfiguration' => [
                'ErrorDocument' => [
                    'Key' => $ErrorFile,
                ],
                'IndexDocument' => [
                    'Suffix' => $IndexFile,
                ],
            ],
        ]);
    }
}
