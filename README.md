# XQL

XQL is an XML persistence, schema synchronization, and database-change daemon toolkit for PHP applications.

It stores durable XML model instances in object storage while keeping relational databases focused on simple operational records. XQL model definitions describe how database rows, relationships, computed values, searchable fields, hooks, and schema migrations become long-lived XML documents. The Windsor daemon watches database changes, queues affected model instances, rebuilds XML, and writes the updated files to the configured storage backend.

XQL is maintained by Fortrow, LLC and distributed under the MIT license.

## Installation

```bash
composer require fortrow/xql
```

XQL ships with the `windsor` CLI:

```bash
vendor/bin/windsor
```

## Configuration

Copy `.env.example` from the package root into your project-specific environment configuration and set the database, storage, model discovery, logging, daemon, and mail values for your application.

## What XQL provides

- XML model definitions for application data that should be retained cheaply for years.
- Object storage writers for AWS S3, Azure Blob Storage, Google Cloud Storage, and local disk.
- MySQL/MariaDB metadata tables for model definitions, schema signatures, XML instance indexes, search indexes, jobs, hooks, bindings, and binlog checkpoints.
- Automatic XML rebuilds when bound database rows change.
- Schema signature tracking so existing XML instances can be migrated when model definitions change.
- A daemon process that can resume from the last saved binlog file and position after restarts.
- Configurable logging, health reporting, and alert email support.
- Framework-neutral PHP APIs that can be used from Laravel, Symfony, Slim, custom PHP apps, workers, or CLIs.

## Supported object storage

Set `XQL_CLOUD_DRIVER` to choose where XML files are stored.

| Driver | Values | Notes |
| --- | --- | --- |
| Amazon S3 | `s3`, `aws` | Uses the AWS PHP SDK. Supports explicit keys or instance/task roles through the SDK default credential chain. |
| Azure Blob Storage | `azure`, `azure-blob`, `azure_blob`, `blob` | Uses Azure Blob Shared Key authentication through the Blob REST API. |
| Google Cloud Storage | `gcs`, `google`, `google-cloud-storage`, `google-cloud`, `google_cloud` | Uses `google/cloud-storage`. Supports service account key files and Application Default Credentials. |
| Local disk | `local`, `disk`, `filesystem` | Intended for development, CI, and small self-hosted installs. |

### Storage configuration

```env
# Supported values: s3, azure, gcs, local
XQL_CLOUD_DRIVER=s3

XQL_AWS_S3_REGION=us-east-2
XQL_AWS_S3_KEY=
XQL_AWS_S3_SECRET=
XQL_AWS_S3_BUCKET=

XQL_AZURE_BLOB_CONTAINER=
XQL_AZURE_BLOB_CONNECTION_STRING=
XQL_AZURE_BLOB_ACCOUNT_NAME=
XQL_AZURE_BLOB_ACCOUNT_KEY=
XQL_AZURE_BLOB_ENDPOINT=
XQL_AZURE_BLOB_ENDPOINT_SUFFIX=core.windows.net
XQL_AZURE_BLOB_PROTOCOL=https

XQL_GCP_STORAGE_BUCKET=
XQL_GCP_PROJECT_ID=
XQL_GCP_KEY_FILE_PATH=
GOOGLE_APPLICATION_CREDENTIALS=

XQL_LOCAL_STORAGE_PATH=storage/xql
```

## Supported relational databases

XQL currently targets MySQL-compatible relational databases because Windsor consumes row-based binary logs.

| Provider | Product | Status | Change source |
| --- | --- | --- | --- |
| AWS | Amazon RDS for MySQL | Supported | Remote MySQL binlog stream |
| AWS | Amazon RDS for MariaDB | Supported | Remote MariaDB/MySQL binlog stream |
| AWS | Amazon Aurora MySQL-Compatible Edition | Supported | Remote MySQL binlog stream |
| Self-hosted VPS | MySQL 8.x | Supported | Local or remote `mysqlbinlog` stream |
| Self-hosted VPS | MariaDB 10.x/11.x | Supported | Local or remote `mysqlbinlog` / `mariadb-binlog` stream |
| Azure | Azure Database for MySQL Flexible Server | Supported | Remote MySQL binlog stream |
| Google Cloud | Cloud SQL for MySQL | Supported | Remote MySQL binlog stream |

PostgreSQL, SQL Server, SQLite, and non-MySQL-compatible databases are not supported by the Windsor binlog daemon.

## Database requirements

XQL requires two logical MySQL-compatible connections:

1. The XQL metadata database.
2. The application database being watched.

They can be separate databases on the same server, separate schemas on the same managed instance, or different servers entirely.

```env
XQL_DB_DRIVER=mariadb
XQL_DB_HOST=127.0.0.1
XQL_DB_PORT=3306
XQL_DB_USERNAME=
XQL_DB_PASSWORD=
XQL_DB_DATABASE=xql

XQL_BINDED_DB_DRIVER=mariadb
XQL_BINDED_DB_HOST=127.0.0.1
XQL_BINDED_DB_PORT=3306
XQL_BINDED_DB_USERNAME=
XQL_BINDED_DB_PASSWORD=
XQL_BINDED_DB_DATABASE=app
```

The watched database must provide:

- MySQL-compatible row-based binary logs.
- A stable primary key on every table used by XQL bindings or hooks.
- A binlog user with replication/binlog read permissions.
- Enough binlog retention for the Windsor daemon to recover from downtime.
- Network access from the Windsor host to the database endpoint.

## Binlog configuration

Windsor uses `mysqlbinlog` or `mariadb-binlog` to stream row changes and convert them into XQL jobs.

```env
XQL_BINLOG_MYSQLBINLOG=mysqlbinlog
XQL_BINLOG_USERNAME="${XQL_BINDED_DB_USERNAME}"
XQL_BINLOG_PASSWORD="${XQL_BINDED_DB_PASSWORD}"
XQL_BINLOG_FILE=
XQL_BINLOG_POSITION=
```

If `XQL_BINLOG_FILE` and `XQL_BINLOG_POSITION` are empty, Windsor resumes from the latest checkpoint saved in the XQL metadata database. For first boot, provide the current binlog file and position or seed a checkpoint before starting the daemon.

Useful SQL checks:

```sql
SHOW VARIABLES LIKE 'log_bin';
SHOW VARIABLES LIKE 'binlog_format';
SHOW BINARY LOGS;
SHOW MASTER STATUS;
```

For MySQL 8.4 and newer, row-based logging is the expected path. On older MySQL versions, set `binlog_format=ROW` where the provider exposes that setting.

## Provider notes

### AWS RDS and Aurora MySQL

Use Amazon RDS for MySQL, Amazon RDS for MariaDB, or Aurora MySQL-Compatible Edition with binary logging enabled. For RDS MySQL, automated backups must have a retention period greater than zero for binary logging to be enabled. Use a DB parameter group with row-based binary logging for predictable XQL updates.

The binlog user needs permission to stream binary logs from the DB endpoint. A typical setup is:

```sql
CREATE USER 'xql_binlog'@'%' IDENTIFIED BY 'change-me';
GRANT REPLICATION SLAVE, REPLICATION CLIENT ON *.* TO 'xql_binlog'@'%';
FLUSH PRIVILEGES;
```

Then configure Windsor with the RDS/Aurora endpoint:

```env
XQL_BINDED_DB_HOST=my-db.cluster-xxxxxxxxxxxx.us-east-2.rds.amazonaws.com
XQL_BINLOG_USERNAME=xql_binlog
XQL_BINLOG_PASSWORD=change-me
```

### Self-hosted VPS MySQL/MariaDB

Self-hosted installs can run Windsor on the same VPS as MySQL or on another trusted host. Enable binary logging and row format in MySQL/MariaDB configuration.

Example MySQL configuration:

```ini
[mysqld]
server-id=1001
log_bin=mysql-bin
binlog_format=ROW
binlog_row_image=FULL
expire_logs_days=7
```

For MariaDB, use the equivalent MariaDB server options and point `XQL_BINLOG_MYSQLBINLOG` at `mariadb-binlog` if that is the installed binary:

```env
XQL_BINLOG_MYSQLBINLOG=mariadb-binlog
```

Create a replication/binlog user:

```sql
CREATE USER 'xql_binlog'@'%' IDENTIFIED BY 'change-me';
GRANT REPLICATION SLAVE, REPLICATION CLIENT ON *.* TO 'xql_binlog'@'%';
FLUSH PRIVILEGES;
```

When Windsor runs locally on the database host, `XQL_BINDED_DB_HOST=127.0.0.1` is acceptable. For remote Windsor hosts, bind MySQL to a private interface, require TLS where appropriate, and firewall port `3306` to only the Windsor host.

### Azure Database for MySQL Flexible Server

Use Azure Database for MySQL Flexible Server. Azure Flexible Server keeps binary logs enabled and uses row-based binary logging. Configure binlog retention long enough for Windsor to recover from daemon downtime.

Recommended settings:

```env
XQL_BINDED_DB_HOST=my-server.mysql.database.azure.com
XQL_BINDED_DB_PORT=3306
XQL_BINLOG_MYSQLBINLOG=mysqlbinlog
```

Create a database user for Windsor with replication/binlog permissions according to the access model available on the Azure server. Network access should be private endpoint or firewall-limited to the Windsor host.

### Google Cloud SQL for MySQL

Use Cloud SQL for MySQL with point-in-time recovery / binary logging enabled. In Google Cloud, enabling PITR enables binary logging for the primary instance. Configure retained transaction log days long enough for Windsor recovery.

Example gcloud setup:

```bash
gcloud sql instances patch INSTANCE_NAME --enable-bin-log --retained-transaction-log-days=7
```

Configure Windsor with the Cloud SQL private IP, public IP, or connector/proxy endpoint used by your deployment:

```env
XQL_BINDED_DB_HOST=10.0.0.10
XQL_BINDED_DB_PORT=3306
XQL_BINLOG_MYSQLBINLOG=mysqlbinlog
```

Use private IP or the Cloud SQL Auth Proxy/connector where possible. The binlog user must be able to read binary logs and table metadata.

## Running Windsor

Install or update XQL metadata tables:

```bash
vendor/bin/windsor install --sync-models --create-instances
```

Start the daemon in queue-processing mode:

```bash
vendor/bin/windsor daemon
```

Start the daemon in binlog mode:

```bash
vendor/bin/windsor daemon --binlog --binlog-file=mysql-bin.000001 --binlog-position=4
```

After the first checkpoint is saved, Windsor can restart without explicit file and position arguments:

```bash
vendor/bin/windsor daemon --binlog
```

The generated systemd unit uses the same behavior and resumes from the saved XQL checkpoint:

```bash
vendor/bin/windsor install --systemd-unit --binlog --unit-path=/tmp/xql-windsor.service
```

## Model discovery

Application-specific XQL model classes should live in the consuming application. Expose them through explicit class names or directories:

```env
XQL_MODEL_CLASSES=
XQL_MODEL_DIRECTORIES=app/Classes/XQL
```

Laravel example:

```json
{
  "require": {
    "fortrow/xql": "^1.0"
  },
  "autoload": {
    "psr-4": {
      "App\\": "app/"
    }
  }
}
```

Model classes can remain in the application namespace, for example `App\Classes\XQL\Results\Results`, while the package runtime remains under `XQL\`.

## Monitoring and logs

```env
XQL_DAEMON_LOG_PATH=storage/logs/xql/windsor.log
XQL_DAEMON_ALERT_STATE_PATH=storage/logs/xql/alert-state.json
XQL_DAEMON_ALERT_EMAILS=
XQL_DAEMON_ALERT_COOLDOWN_SECONDS=900
XQL_DAEMON_HEARTBEAT_MINUTES=0
XQL_DAEMON_LOAD_ALERT_THRESHOLD=0
XQL_DAEMON_HEALTH_LOG_INTERVAL_SECONDS=300
```

If configured, Windsor writes local logs and can send fault, high-load, heartbeat, and fatal-shutdown emails through Symfony Mailer-compatible SMTP settings.

```env
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=25
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=
MAIL_FROM_ADDRESS=mailer@example.com
MAIL_FROM_NAME=XQL
```

## Production guidance

- Run Windsor close to the database to reduce binlog stream latency.
- Use private networking wherever possible.
- Keep binlog retention longer than the expected maximum daemon downtime.
- Use row-based binary logging and full row images for reliable XML rebuilds.
- Give Windsor read access to table metadata and only the replication/binlog permissions it needs.
- Keep the XQL metadata database backed up; it contains instance paths, schema signatures, queue state, searchable indexes, and binlog checkpoints.
- Store XML files in durable object storage for production workloads.

## License

MIT. See `LICENSE`.
