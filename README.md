This driver provides the functionality to save your model audit records as lines in CSV files. It is seamlessly integrated
with Laravel Storage System, enabling you to use any of the registered disks specified in your application as storage
destinations for the audit files.

Additionally, the driver offers flexibility in how the audit files are generated, allowing you to choose between creating
a single comprehensive file or generating files for each hour of operation. Moreover, the driver can potentially improve
performance by buffering the log records and then flushing them once you have completed making model changes.

### Installation

To utilize this driver, you need to have `owen-it/laravel-auditing: ^14.0` installed. Once this requirement is met, you
can proceed to install the driver as follows:

```
composer require virlatinus/laravel-auditing-filesystem
```

### Setup

If you wish to modify the default behavior of the driver, you must include the following configuration entries in
`config/audit.php`. The drivers key in the configuration file should be structured as follows:

```php
    // ...
    'drivers' => [
        'database' => [
            'table'      => 'audits',
            'connection' => null,
        ],
        'filesystem' => [
            'disk'      => 'local',     // The registered name of any filesystem disk in the application
            'dir'       => 'audit',     // The directory on the disk where the audit csv files will be saved
            'filename'  => 'audit.csv', // The filename of the audit file
            'rotation'  => 'single',    // One of 'single', 'monthly', 'weekly', 'daily', or 'hourly'
        ],
    ],
    // ...
```

### Usage

You can integrate the driver into any Auditable model by following the code snippet below:

```php
<?php
namespace App\Models;

use OneSeven9955\Auditing\Drivers\FilesystemDriver;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class Article extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    /**
     * Filesystem Audit Driver.
     *
     * @var \OneSeven9955\Auditing\Drivers\FilesystemDriver
     */
    protected $auditDriver = FilesystemDriver::class;

    // ...
}
```

To optimize the process of writing audit records, consider buffering the records and writing
them in bulk rather than individually. This approach helps reduce I/O operations, such as
acquiring exclusive file locks and opening files repeatedly.

You can implement this optimization by following these steps:

```php
use OneSeven9955\Auditing\Drivers\FilesystemDriver;

app(FilesystemDriver::class)->bufferStart();
    // ...
    // PERFORM MODEL CHANGES
    // ...
app(FilesystemDriver::class)->bufferFlush(); // flush all audit records into a file at once
```
