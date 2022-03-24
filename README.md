# WordPress: Mirror Uploads to S3

WordPress plugin to mirror uploads directory to an S3 bucket.

## Dependencies

This plugin utilizes the [PHP AWS SDK](https://github.com/aws/aws-sdk-php), and as a result should support any configuration options native to the SDK. Refer to the [SDK documentation](https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/guide_index.html) for more details.

> This plugin is a small project developed to be included in my personal projects. If there is any further interest or ideas, I am happy to consider them.

## Environment Variables

`S3_UPLOADS_ENDPOINT`<br />
HTTP endpoint for accessing an S3-compatible API endpoint using [path-style access](https://docs.aws.amazon.com/AmazonS3/latest/userguide/access-bucket-intro.html).

`S3_UPLOADS_BUCKET`<br />
Name of the bucket to upload files to. <br />
_(This can include a path to a subdirectory intended for files to be uploaded to: eg, `bucket_name/path/to/subdirectory`)_

`S3_UPLOADS_REGION`<br />
Region of the S3 bucket.

`S3_UPLOADS_KEY`<br />
S3 access key id.

`S3_UPLOADS_SECRET`<br />
S3 access key secret.

`S3_UPLOADS_BUCKET_URL`<br />
URL used for WordPress media URL's.
