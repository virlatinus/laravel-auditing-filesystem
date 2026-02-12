<?php

namespace VirLatinus\Auditing\Drivers;

use DateTime;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use League\Csv\Writer;
use OwenIt\Auditing\Contracts\Audit;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Contracts\AuditDriver;

final class FilesystemDriver implements AuditDriver
{
    private FilesystemAdapter $disk;

    private string $dir;

    private string $filename;

    private Rotation $rotation;

    private bool $buffering = false;
    private \SplFixedArray $buffer;
    private int $bufferLen = 0;

    public function __construct()
    {
        $this->disk = Storage::disk(Config::get('audit.drivers.filesystem.disk', 'local'));
        $this->dir = Config::get('audit.drivers.filesystem.dir', '');
        $this->filename = Config::get('audit.drivers.filesystem.filename', 'audit.csv');

        $rotationValue = Config::get('audit.drivers.filesystem.rotation', 'single');
        $rotation = Rotation::tryFrom($rotationValue);
        if ($rotation === null) {
            throw new \InvalidArgumentException(sprintf("Unsupported rotation value '%s'", $rotationValue));
        }
        $this->rotation = $rotation;

        $this->buffer = new \SplFixedArray(128);
    }

    public function bufferStart(): void
    {
        $this->buffering = true;
    }

    public function bufferFlush(): void
    {
        if (0 === $this->bufferLen) {
            $this->buffering = false;
            $this->bufferLen = 0;
            return;
        }

        if (strlen($this->dir) > 0 && $this->disk->directoryMissing($this->dir)) {
            $this->disk->makeDirectory($this->dir);
        }

        $filepath = $this->disk->path($this->auditFilepath());

        $stream = $this->openFile($filepath);
        $reader = Reader::createFromStream($stream);
        $header = $reader->first();

        $writer = Writer::createFromStream($stream);

        if (count($header) === 0) {
            $writer->setFlushThreshold($this->bufferLen + 1);
            $writer->insertOne($this->headerRow($this->buffer[0]));
        } else {
            $writer->setFlushThreshold($this->bufferLen);
        }

        for ($i = 0; $i < $this->bufferLen; ++$i) {
            $writer->insertOne($this->buffer[$i]);
        }

        $this->closeFile($stream);

        $this->buffering = false;
        $this->bufferLen = 0;
    }

    private function bufferPush(array $row): void
    {
        if ($this->bufferLen >= $this->buffer->getSize() - 1) {
            $this->buffer->setSize($this->buffer->getSize() * 2);
        }

        $this->buffer[$this->bufferLen++] = $row;
    }

    /**
     * {@inheritdoc}
     */
    public function audit(Auditable $model): ?Audit
    {
        $this->bufferPush($this->prepareAuditValues($this->getAuditFromModel($model)));

        if (false === $this->buffering) {
            $this->bufferFlush();
        }

        $implementation = Config::get('audit.implementation', \OwenIt\Auditing\Models\Audit::class);

        return new $implementation();
    }

    private function acuireLock(mixed $stream, int $timeoutSeconds): bool
    {
        $now = new \DateTimeImmutable('now');
        $deadline = $now->add(\DateInterval::createFromDateString("$timeoutSeconds seconds"));

        while ($now->getTimestamp() < $deadline->getTimestamp()) {
            if (\flock($stream, LOCK_EX | LOCK_NB)) {
                return true;
            }
            usleep(5 * 1000); // sleep 5ms
            $now = new \DateTimeImmutable('now');
        }

        return false;
    }

    private function openFile(string $filepath): mixed
    {
        $stream = \fopen($filepath, 'a+');

        if ($this->acuireLock($stream, 10) === false) {
            throw new \RuntimeException("Could not acquire lock within 10 seconds");
        }

        return $stream;
    }

    private function closeFile(mixed $stream): void
    {
        \fflush($stream);
        \flock($stream, LOCK_UN);
    }

    /**
     * {@inheritdoc}
     */
    public function prune(Auditable $model): bool
    {
        return false;
    }

    /**
     * Serialize the 'old_values' and 'new_values' values before inserting
     * them as a row in a csv file.
     */
    protected function prepareAuditValues(array $audit): array
    {
        $audit['old_values'] = json_encode($audit['old_values']);
        $audit['new_values'] = json_encode($audit['new_values']);

        return $audit;
    }

    /**
     * Determine the current audit filepath based on the rotation
     * setting in the configuration.
     *
     * @return string
     */
    protected function auditFilepath(): string
    {
        return match ($this->rotation) {
            Rotation::Single => (function (): string {
                if (strlen($this->dir) > 0) {
                    return sprintf("%s/%s", $this->dir, $this->filename);
                }

                return $this->filename;
            })(),
            Rotation::Daily => (function (): string {
                $date = (new \DateTime('now'))->format('Y-m-d');
                if (strlen($this->dir) > 0) {
                    return sprintf("%s/audit-%s.csv", $this->dir, $date);
                }

                return sprintf("audit-%s.csv", $date);
            })(),
            Rotation::Hourly => (function (): string {
                $dateTime = (new \DateTime('now'))->format('Y-m-d-H');

                if (strlen($this->dir) > 0) {
                    return sprintf("%s/audit-%s-00-00.csv", $this->dir, $dateTime);
                }

                return sprintf("audit-%s-00-00.csv", $dateTime);
            })(),
        };
    }

    /**
     * Transform an Auditable model into an audit array.
     */
    protected function getAuditFromModel(Auditable $model): array
    {
        return $this->appendCreatedAt($model->toAudit());
    }

    /**
     * Append a created_at key to the audit array.
     */
    protected function appendCreatedAt(array $audit): array
    {
        $audit['created_at'] = (new DateTime('now'))->format('Y-m-d H:i:s');
        return $audit;
    }

    /**
    * Generate a header row from an audit array, based on the key strings.
     */
    protected function headerRow(array $audit): array
    {
        return array_map(static function (string $key): string {
            return ucwords(str_replace('_', ' ', $key));
        }, array_keys($audit));
    }
}
