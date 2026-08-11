<?php

namespace XQL\Cloud;

use XQL\Core\Utils\Env;
use Aws\S3\S3Client;

class S3 implements CloudDriver
{

    private string $bucket;

    private S3Client $s3;

    public function __construct()
    {

        $region = Env::get("XQL_AWS_S3_REGION");
        $key = Env::get("XQL_AWS_S3_KEY");
        $secret = Env::get("XQL_AWS_S3_SECRET");
        $this->bucket = Env::get("XQL_AWS_S3_BUCKET");

        $config = [
            'version' => 'latest',
            'region' => $region,
        ];

        if($key && $secret) {
            $config['credentials'] = [
                'key'    => $key,
                'secret' => $secret,
            ];
        }

        $this->s3 = new S3Client($config);

    }

    public function put(string $key, string $content) {
        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => $content
        ]);
    }

    public function get(string $key) {
       $res = $this->s3->getObject([
            'Bucket' => $this->bucket,
            'Key' => $key
        ]);
       return $res['Body']->getContents();
    }

}
