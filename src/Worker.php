<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

declare(strict_types=1);

namespace Workerman;

use AllowDynamicProperties;
use Exception;
use Revolt\EventLoop;
use RuntimeException;
use stdClass;
use Throwable;
use Workerman\Connection\ConnectionInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Connection\UdpConnection;
use Workerman\Events\Event;
use Workerman\Events\EventInterface;
use Workerman\Events\Revolt;
use Workerman\Events\Select;
use Workerman\Protocols\ProtocolInterface;
use function method_exists;
use function restore_error_handler;
use function set_error_handler;
use function stream_socket_accept;
use function stream_socket_recvfrom;
use function substr;

/**
 * Worker class
 * A container for listening ports
 */
#[AllowDynamicProperties]
class Worker
{
    /**
     * Version.
     *
     * @var string
     */
    public const VERSION = '5.0.0-beta.3';

    /**
     * Status starting.
     *
     * @var int
     */
    public const STATUS_STARTING = 1;

    /**
     * Status running.
     *
     * @var int
     */
    public const STATUS_RUNNING = 2;

    /**
     * Status shutdown.
     *
     * @var int
     */
    public const STATUS_SHUTDOWN = 4;

    /**
     * Status reloading.
     *
     * @var int
     */
    public const STATUS_RELOADING = 8;

    /**
     * Default backlog. Backlog is the maximum length of the queue of pending connections.
     *
     * @var int
     */
    public const DEFAULT_BACKLOG = 102400;

    /**
     * The safe distance for columns adjacent
     *
     * @var int
     */
    public const UI_SAFE_LENGTH = 4;

    /**
     * Worker id.
     *
     * @var int
     */
    public int $id = 0;

    /**
     * Name of the worker processes.
     *
     * @var string
     */
    public string $name = 'none';

    /**
     * Number of worker processes.
     *
     * @var int
     */
    public int $count = 1;

    /**
     * Unix user of processes, needs appropriate privileges (usually root).
     *
     * @var string
     */
    public string $user = '';

    /**
     * Unix group of processes, needs appropriate privileges (usually root).
     *
     * @var string
     */
    public string $group = '';

    /**
     * reloadable.
     *
     * @var bool
     */
    public bool $reloadable = true;

    /**
     * reuse port.
     *
     * @var bool
     */
    public bool $reusePort = false;

    /**
     * Emitted when worker processes is starting.
     *
     * @var ?callable
     */
    public $onWorkerStart = null;

    /**
     * Emitted when a socket connection is successfully established.
     *
     * @var ?callable
     */
    public $onConnect = null;

    /**
     * Emitted when websocket handshake did complete (Only called when protocol is ws).
     *
     * @var ?callable
     */
    public $onWebSocketConnect = null;

    /**
     * Emitted when data is received.
     *
     * @var callable
     */
    public $onMessage = null;

    /**
     * Emitted when the other end of the socket sends a FIN packet.
     *
     * @var ?callable
     */
    public $onClose = null;

    /**
     * Emitted when an error occurs with connection.
     *
     * @var ?callable
     */
    public $onError = null;

    /**
     * Emitted when the send buffer becomes full.
     *
     * @var ?callable
     */
    public $onBufferFull = null;

    /**
     * Emitted when the send buffer becomes empty.
     *
     * @var ?callable
     */
    public $onBufferDrain = null;

    /**
     * Emitted when worker processes has stopped.
     *
     * @var ?callable
     */
    public $onWorkerStop = null;

    /**
     * Emitted when worker processes receives reload signal.
     *
     * @var ?callable
     */
    public $onWorkerReload = null;

    /**
     * Transport layer protocol.
     *
     * @var string
     */
    public string $transport = 'tcp';

    /**
     * Store all connections of clients.
     *
     * @var TcpConnection[]
     */
    public array $connections = [];

    /**
     * Application layer protocol.
     *
     * @var ?string
     */
    public ?string $protocol = null;

    /**
     * Pause accept new connections or not.
     *
     * @var bool
     */
    protected bool $pauseAccept = true;

    /**
     * Is worker stopping ?
     * @var bool
     */
    public bool $stopping = false;

    /**
     * Daemonize.
     *
     * @var bool
     */
    public static bool $daemonize = false;

    /**
     * Stdout file.
     *
     * @var string
     */
    public static string $stdoutFile = '/dev/null';

    /**
     * The file to store master process PID.
     *
     * @var string
     */
    public static string $pidFile = '';

    /**
     * The file used to store the master process status file.
     *
     * @var string
     */
    public static string $statusFile = '';

    /**
     * Log file.
     *
     * @var mixed
     */
    public static mixed $logFile = '';

    /**
     * Global event loop.
     *
     * @var ?EventInterface
     */
    public static ?EventInterface $globalEvent = null;

    /**
     * Emitted when the master process get reload signal.
     *
     * @var ?callable
     */
    public static $onMasterReload = null;

    /**
     * Emitted when the master process terminated.
     *
     * @var ?callable
     */
    public static $onMasterStop = null;

    /**
     * Emitted when worker processes exited.
     *
     * @var ?callable
     */
    public static $onWorkerExit = null;

    /**
     * EventLoopClass
     *
     * @var class-string
     */
    public static string $eventLoopClass = '';

    /**
     * Process title
     *
     * @var string
     */
    public static string $processTitle = 'WorkerMan';

    /**
     * After sending the stop command to the child process stopTimeout seconds,
     * if the process is still living then forced to kill.
     *
     * @var int
     */
    public static int $stopTimeout = 2;

    /**
     * Command
     * @var string
     */
    public static string $command = '';

    /**
     * The PID of master process.
     *
     * @var int
     */
    protected static int $masterPid = 0;

    /**
     * Listening socket.
     *
     * @var resource
     */
    protected $mainSocket = null;

    /**
     * Socket name. The format is like this http://0.0.0.0:80 .
     *
     * @var string
     */
    protected string $socketName = '';

    /**
     * parse from socketName avoid parse again in master or worker
     * LocalSocket The format is like tcp://0.0.0.0:8080
     * @var ?string
     */
    protected ?string $localSocket = null;

    /**
     * Context of socket.
     *
     * @var resource
     */
    protected $socketContext = null;

    /**
     * @var stdClass
     */
    protected stdClass $context;

    /**
     * All worker instances.
     *
     * @var Worker[]
     */
    protected static array $workers = [];

    /**
     * All worker processes pid.
     * The format is like this [worker_id=>[pid=>pid, pid=>pid, ..], ..]
     *
     * @var array
     */
    protected static array $pidMap = [];

    /**
     * All worker processes waiting for restart.
     * The format is like this [pid=>pid, pid=>pid].
     *
     * @var array
     */
    protected static array $pidsToRestart = [];

    /**
     * Mapping from PID to worker process ID.
     * The format is like this [worker_id=>[0=>$pid, 1=>$pid, ..], ..].
     *
     * @var array
     */
    protected static array $idMap = [];

    /**
     * Current status.
     *
     * @var int
     */
    protected static int $status = self::STATUS_STARTING;

    /**
     * Maximum length of the worker names.
     *
     * @var int
     */
    protected static int $maxWorkerNameLength = 12;

    /**
     * Maximum length of the socket names.
     *
     * @var int
     */
    protected static int $maxSocketNameLength = 12;

    /**
     * Maximum length of the process usernames.
     *
     * @var int
     */
    protected static int $maxUserNameLength = 12;

    /**
     * Maximum length of the Proto names.
     *
     * @var int
     */
    protected static int $maxProtoNameLength = 4;

    /**
     * Maximum length of the Processes names.
     *
     * @var int
     */
    protected static int $maxProcessesNameLength = 9;

    /**
     * Maximum length of the state names.
     *
     * @var int
     */
    protected static int $maxStateNameLength = 1;

    /**
     * The file to store status info of current worker process.
     *
     * @var string
     */
    protected static string $statisticsFile = '';

    /**
     * Start file.
     *
     * @var string
     */
    protected static string $startFile = '';

    /**
     * Processes for windows.
     *
     * @var array
     */
    protected static array $processForWindows = [];

    /**
     * Status info of current worker process.
     *
     * @var array
     */
    protected static array $globalStatistics = [
        'start_timestamp' => 0,
        'worker_exit_info' => []
    ];

    /**
     * Available event loops.
     *
     * @var array<string, string>
     */
    protected static array $availableEventLoops = [
        'event' => Event::class,
    ];

    /**
     * PHP built-in protocols.
     *
     * @var array<string,string>
     */
    public const BUILD_IN_TRANSPORTS = [
        'tcp' => 'tcp',
        'udp' => 'udp',
        'unix' => 'unix',
        'ssl' => 'tcp'
    ];

    /**
     * PHP built-in error types.
     *
     * @var array<int,string>
     */
    public const ERROR_TYPE = [
        E_ERROR => 'E_ERROR',             // 1
        E_WARNING => 'E_WARNING',           // 2
        E_PARSE => 'E_PARSE',             // 4
        E_NOTICE => 'E_NOTICE',            // 8
        E_CORE_ERROR => 'E_CORE_ERROR',        // 16
        E_CORE_WARNING => 'E_CORE_WARNING',      // 32
        E_COMPILE_ERROR => 'E_COMPILE_ERROR',     // 64
        E_COMPILE_WARNING => 'E_COMPILE_WARNING',   // 128
        E_USER_ERROR => 'E_USER_ERROR',        // 256
        E_USER_WARNING => 'E_USER_WARNING',      // 512
        E_USER_NOTICE => 'E_USER_NOTICE',       // 1024
        E_STRICT => 'E_STRICT',            // 2048
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR', // 4096
        E_DEPRECATED => 'E_DEPRECATED',        // 8192
        E_USER_DEPRECATED => 'E_USER_DEPRECATED'   // 16384
    ];

    /**
     * Graceful stop or not.
     *
     * @var bool
     */
    protected static bool $gracefulStop = false;

    /**
     * Standard output stream
     * @var resource
     */
    protected static $outputStream = null;

    /**
     * If $outputStream support decorated
     * @var bool
     */
    protected static bool $outputDecorated = false;

    /**
     * Worker object's hash id(unique identifier).
     *
     * @var ?string
     */
    protected ?string $workerId = null;

    /**
     * Run all worker instances.
     *
     * @return void
     * @throws Throwable
     */
    public static function runAll(): void
    {
        static::checkSapiEnv();
        static::init();
        static::parseCommand();
        static::lock();
        static::daemonize();
        static::initWorkers();
        static::installSignal();
        static::saveMasterPid();
        static::lock(LOCK_UN);
        static::displayUI();
        static::forkWorkers();
        static::resetStd();
        static::monitorWorkers();
    }

    /**
     * Check sapi.
     *
     * @return void
     */
    protected static function checkSapiEnv(): void
    {
        // Only for cli.
        if (PHP_SAPI !== 'cli') {
            exit("Only run in command line mode \n");
        }
    }

    /**
     * Init.
     *
     * @return void
     */
    protected static function init(): void
    {
        set_error_handler(function ($code, $msg, $file, $line) {
            static::safeEcho("$msg in file $file on line $line\n");
        });

        // Start file.
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        static::$startFile = end($backtrace)['file'];

        $uniquePrefix = str_replace('/', '_', static::$startFile);

        // Pid file.
        if (empty(static::$pidFile)) {
            static::$pidFile = __DIR__ . "/../$uniquePrefix.pid";
        }

        // Log file.
        if (empty(static::$logFile)) {
            static::$logFile = __DIR__ . '/../../workerman.log';
        }

        if (!is_file(static::$logFile)) {
            // if /runtime/logs  default folder not exists
            if (!is_dir(dirname(static::$logFile))) {
                @mkdir(dirname(static::$logFile), 0777, true);
            }
            touch(static::$logFile);
            chmod(static::$logFile, 0622);
        }

        // State.
        static::$status = static::STATUS_STARTING;

        // For statistics.
        static::$globalStatistics['start_timestamp'] = time();

        // Process title.
        static::setProcessTitle(static::$processTitle . ': master process  start_file=' . static::$startFile);

        // Init data for worker id.
        static::initId();

        // Timer init.
        Timer::init();
    }

    /**
     * Lock.
     *
     * @param int $flag
     * @return void
     */
    protected static function lock(int $flag = LOCK_EX): void
    {
        global $argv;
        static $fd;
        if (DIRECTORY_SEPARATOR !== '/' || empty($argv)) {
            return;
        }
        $lockFile = static::$pidFile . '.lock';
        $fd = $fd ?: fopen($lockFile, 'a+');
        if ($fd) {
            flock($fd, $flag);
            if ($flag === LOCK_UN) {
                fclose($fd);
                $fd = null;
                clearstatcache();
                if (is_file($lockFile)) {
                    unlink($lockFile);
                }
            }
        }
    }

    /**
     * Init All worker instances.
     *
     * @return void
     * @throws Exception
     */
    protected static function initWorkers(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        static::$statisticsFile = static::$statusFile ?: __DIR__ . '/../workerman-' . posix_getpid() . '.status';
        foreach (static::$workers as $worker) {
            // Worker name.
            if (empty($worker->name)) {
                $worker->name = 'none';
            }

            // Get unix user of the worker process.
            if (empty($worker->user)) {
                $worker->user = static::getCurrentUser();
            } else {
                if (posix_getuid() !== 0 && $worker->user !== static::getCurrentUser()) {
                    static::log('Warning: You must have the root privileges to change uid and gid.');
                }
            }

            // Socket name.
            $worker->context->statusSocket = $worker->getSocketName();

            // Status name.
            $worker->context->statusState = '<g> [OK] </g>';

            // Get column mapping for UI
            foreach (static::getUiColumns() as $columnName => $prop) {
                !isset($worker->$prop) && !isset($worker->context->$prop) && $worker->context->$prop = 'NNNN';
                $propLength = strlen((string)($worker->$prop ?? $worker->context->$prop));
                $key = 'max' . ucfirst(strtolower($columnName)) . 'NameLength';
                static::$$key = max(static::$$key, $propLength);
            }

            // Listen.
            if (!$worker->reusePort) {
                $worker->listen();
            }
        }
    }

    /**
     * Get all worker instances.
     *
     * @return Worker[]
     */
    public static function getAllWorkers(): array
    {
        return static::$workers;
    }

    /**
     * Get global event-loop instance.
     *
     * @return EventInterface
     */
    public static function getEventLoop(): EventInterface
    {
        return static::$globalEvent;
    }

    /**
     * Get main socket resource
     * @return resource
     */
    public function getMainSocket()
    {
        return $this->mainSocket;
    }

    /**
     * Init idMap.
     *
     * @return void
     */
    protected static function initId(): void
    {
        foreach (static::$workers as $workerId => $worker) {
            $newIdMap = [];
            $worker->count = max($worker->count, 1);
            for ($key = 0; $key < $worker->count; $key++) {
                $newIdMap[$key] = static::$idMap[$workerId][$key] ?? 0;
            }
            static::$idMap[$workerId] = $newIdMap;
        }
    }

    /**
     * Get unix user of current porcess.
     *
     * @return string
     */
    protected static function getCurrentUser(): string
    {
        $userInfo = posix_getpwuid(posix_getuid());
        return $userInfo['name'] ?? 'unknown';
    }

    /**
     * Display staring UI.
     *
     * @return void
     */
    protected static function displayUI(): void
    {
        $tmpArgv = static::getArgv();
        if (in_array('-q', $tmpArgv)) {
            return;
        }
        if (DIRECTORY_SEPARATOR !== '/') {
            static::safeEcho("----------------------- WORKERMAN -----------------------------\r\n");
            static::safeEcho('Workerman version:' . static::VERSION . '          PHP version:' . PHP_VERSION . "\r\n");
            static::safeEcho("------------------------ WORKERS -------------------------------\r\n");
            static::safeEcho("worker               listen                              processes status\r\n");
            return;
        }

        //show version
        $lineVersion = 'Workerman version:' . static::VERSION . str_pad('PHP version:', 16, ' ', STR_PAD_LEFT) . PHP_VERSION . str_pad('Event-loop:', 16, ' ', STR_PAD_LEFT) . static::getEventLoopName() . PHP_EOL;
        !defined('LINE_VERSIOIN_LENGTH') && define('LINE_VERSIOIN_LENGTH', strlen($lineVersion));
        $totalLength = static::getSingleLineTotalLength();
        $lineOne = '<n>' . str_pad('<w> WORKERMAN </w>', $totalLength + strlen('<w></w>'), '-', STR_PAD_BOTH) . '</n>' . PHP_EOL;
        $lineTwo = str_pad('<w> WORKERS </w>', $totalLength + strlen('<w></w>'), '-', STR_PAD_BOTH) . PHP_EOL;
        static::safeEcho($lineOne . $lineVersion . $lineTwo);

        //Show title
        $title = '';
        foreach (static::getUiColumns() as $columnName => $prop) {
            $key = 'max' . ucfirst(strtolower($columnName)) . 'NameLength';
            //just keep compatible with listen name
            $columnName === 'socket' && $columnName = 'listen';
            $title .= "<w>$columnName</w>" . str_pad('', static::$$key + static::UI_SAFE_LENGTH - strlen($columnName));
        }
        $title && static::safeEcho($title . PHP_EOL);

        //Show content
        foreach (static::$workers as $worker) {
            $content = '';
            foreach (static::getUiColumns() as $columnName => $prop) {
                $propValue = (string)($worker->$prop ?? $worker->context->$prop);
                $key = 'max' . ucfirst(strtolower($columnName)) . 'NameLength';
                preg_match_all("/(<n>|<\/n>|<w>|<\/w>|<g>|<\/g>)/i", $propValue, $matches);
                $placeHolderLength = !empty($matches) ? strlen(implode('', $matches[0])) : 0;
                $content .= str_pad($propValue, static::$$key + static::UI_SAFE_LENGTH + $placeHolderLength);
            }
            $content && static::safeEcho($content . PHP_EOL);
        }

        //Show last line
        $lineLast = str_pad('', static::getSingleLineTotalLength(), '-') . PHP_EOL;
        !empty($content) && static::safeEcho($lineLast);

        if (static::$daemonize) {
            global $argv;
            $startFile = $argv[0];
            static::safeEcho('Input "php ' . $startFile . ' stop" to stop. Start success.' . "\n\n");
        } else {
            static::safeEcho("Press Ctrl+C to stop. Start success.\n");
        }
    }

    /**
     * Get UI columns to be shown in terminal
     *
     * 1. $columnMap: ['ui_column_name' => 'clas_property_name']
     * 2. Consider move into configuration in future
     *
     * @return array
     */
    public static function getUiColumns(): array
    {
        return [
            'proto' => 'transport',
            'user' => 'user',
            'worker' => 'name',
            'socket' => 'statusSocket',
            'processes' => 'count',
            'state' => 'statusState',
        ];
    }

    /**
     * Get single line total length for ui
     *
     * @return int
     */
    public static function getSingleLineTotalLength(): int
    {
        $totalLength = 0;

        foreach (static::getUiColumns() as $columnName => $prop) {
            $key = 'max' . ucfirst(strtolower($columnName)) . 'NameLength';
            $totalLength += static::$$key + static::UI_SAFE_LENGTH;
        }

        //Keep beauty when show less columns
        !defined('LINE_VERSIOIN_LENGTH') && define('LINE_VERSIOIN_LENGTH', 0);
        $totalLength <= LINE_VERSIOIN_LENGTH && $totalLength = LINE_VERSIOIN_LENGTH;

        return $totalLength;
    }

    /**
     * Parse command.
     *
     * @return void
     */
    protected static function parseCommand(): void
    {
        global $argv;

        if (DIRECTORY_SEPARATOR !== '/' || empty($argv)) {
            return;
        }

        // Check argv;
        $startFile = $argv[0];
        $usage = "Usage: php yourfile <command> [mode]\nCommands: \nstart\t\tStart worker in DEBUG mode.\n\t\tUse mode -d to start in DAEMON mode.\nstop\t\tStop worker.\n\t\tUse mode -g to stop gracefully.\nrestart\t\tRestart workers.\n\t\tUse mode -d to start in DAEMON mode.\n\t\tUse mode -g to stop gracefully.\nreload\t\tReload codes.\n\t\tUse mode -g to reload gracefully.\nstatus\t\tGet worker status.\n\t\tUse mode -d to show live status.\nconnections\tGet worker connections.\n";
        $availableCommands = [
            'start',
            'stop',
            'restart',
            'reload',
            'status',
            'connections',
        ];
        $availableMode = [
            '-d',
            '-g'
        ];
        $command = $mode = '';
        foreach (static::getArgv() as $value) {
            if (in_array($value, $availableCommands)) {
                $command = $value;
            } elseif (in_array($value, $availableMode)) {
                $mode = $value;
            }
        }

        if (!$command) {
            exit($usage);
        }

        // Start command.
        $modeStr = '';
        if ($command === 'start') {
            if ($mode === '-d' || static::$daemonize) {
                $modeStr = 'in DAEMON mode';
            } else {
                $modeStr = 'in DEBUG mode';
            }
        }
        static::log("Workerman[$startFile] $command $modeStr");

        // Get master process PID.
        $masterPid = is_file(static::$pidFile) ? (int)file_get_contents(static::$pidFile) : 0;
        // Master is still alive?
        if (static::checkMasterIsAlive($masterPid)) {
            if ($command === 'start') {
                static::log("Workerman[$startFile] already running");
                exit;
            }
        } elseif ($command !== 'start' && $command !== 'restart') {
            static::log("Workerman[$startFile] not run");
            exit;
        }

        $statisticsFile = static::$statusFile ?: __DIR__ . "/../workerman-$masterPid.$command";

        // execute command.
        switch ($command) {
            case 'start':
                if ($mode === '-d') {
                    static::$daemonize = true;
                }
                break;
            case 'status':
                while (1) {
                    if (is_file($statisticsFile)) {
                        @unlink($statisticsFile);
                    }
                    // Master process will send SIGIOT signal to all child processes.
                    posix_kill($masterPid, SIGIOT);
                    // Sleep 1 second.
                    sleep(1);
                    // Clear terminal.
                    if ($mode === '-d') {
                        static::safeEcho("\33[H\33[2J\33(B\33[m", true);
                    }
                    // Echo status data.
                    static::safeEcho(static::formatStatusData($statisticsFile));
                    if ($mode !== '-d') {
                        @unlink($statisticsFile);
                        exit(0);
                    }
                    static::safeEcho("\nPress Ctrl+C to quit.\n\n");
                }
            case 'connections':
                if (is_file($statisticsFile) && is_writable($statisticsFile)) {
                    unlink($statisticsFile);
                }
                // Master process will send SIGIO signal to all child processes.
                posix_kill($masterPid, SIGIO);
                // Waiting amoment.
                usleep(500000);
                // Display statisitcs data from a disk file.
                if (is_readable($statisticsFile)) {
                    readfile($statisticsFile);
                }
                exit(0);
            case 'restart':
            case 'stop':
                if ($mode === '-g') {
                    static::$gracefulStop = true;
                    $sig = SIGQUIT;
                    static::log("Workerman[$startFile] is gracefully stopping ...");
                } else {
                    static::$gracefulStop = false;
                    $sig = SIGINT;
                    static::log("Workerman[$startFile] is stopping ...");
                }
                // Send stop signal to master process.
                $masterPid && posix_kill($masterPid, $sig);
                // Timeout.
                $timeout = static::$stopTimeout + 3;
                $startTime = time();
                // Check master process is still alive?
                while (1) {
                    $masterIsAlive = $masterPid && posix_kill($masterPid, 0);
                    if ($masterIsAlive) {
                        // Timeout?
                        if (!static::$gracefulStop && time() - $startTime >= $timeout) {
                            static::log("Workerman[$startFile] stop fail");
                            exit;
                        }
                        // Waiting amoment.
                        usleep(10000);
                        continue;
                    }
                    // Stop success.
                    static::log("Workerman[$startFile] stop success");
                    if ($command === 'stop') {
                        exit(0);
                    }
                    if ($mode === '-d') {
                        static::$daemonize = true;
                    }
                    break;
                }
                break;
            case 'reload':
                if ($mode === '-g') {
                    $sig = SIGUSR2;
                } else {
                    $sig = SIGUSR1;
                }
                posix_kill($masterPid, $sig);
                exit;
            default :
                static::safeEcho('Unknown command: ' . $command . "\n");
                exit($usage);
        }
    }

    /**
     * Get argv.
     *
     * @return array
     */
    public static function getArgv(): array
    {
        global $argv;
        return isset($argv[1]) ? $argv : (static::$command ? explode(' ', static::$command) : $argv);
    }

    /**
     * Format status data.
     *
     * @param $statisticsFile
     * @return string
     */
    protected static function formatStatusData($statisticsFile): string
    {
        static $totalRequestCache = [];
        if (!is_readable($statisticsFile)) {
            return '';
        }
        $info = file($statisticsFile, FILE_IGNORE_NEW_LINES);
        if (!$info) {
            return '';
        }
        $statusStr = '';
        $currentTotalRequest = [];
        $workerInfo = unserialize($info[0]);
        ksort($workerInfo, SORT_NUMERIC);
        unset($info[0]);
        $dataWaitingSort = [];
        $readProcessStatus = false;
        $totalRequests = 0;
        $totalQps = 0;
        $totalConnections = 0;
        $totalFails = 0;
        $totalMemory = 0;
        $totalTimers = 0;
        $maxLen1 = static::$maxSocketNameLength;
        $maxLen2 = static::$maxWorkerNameLength;
        foreach ($info as $value) {
            if (!$readProcessStatus) {
                $statusStr .= $value . "\n";
                if (preg_match('/^pid.*?memory.*?listening/', $value)) {
                    $readProcessStatus = true;
                }
                continue;
            }
            if (preg_match('/^[0-9]+/', $value, $pidMath)) {
                $pid = $pidMath[0];
                $dataWaitingSort[$pid] = $value;
                if (preg_match('/^\S+?\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?/', $value, $match)) {
                    $totalMemory += (int)str_ireplace('M', '', $match[1]);
                    $maxLen1 = max($maxLen1, strlen($match[2]));
                    $maxLen2 = max($maxLen2, strlen($match[3]));
                    $totalConnections += (int)$match[4];
                    $totalFails += (int)$match[5];
                    $totalTimers += (int)$match[6];
                    $currentTotalRequest[$pid] = $match[7];
                    $totalRequests += (int)$match[7];
                }
            }
        }
        foreach ($workerInfo as $pid => $info) {
            if (!isset($dataWaitingSort[$pid])) {
                $statusStr .= "$pid\t" . str_pad('N/A', 7) . " "
                    . str_pad($info['listen'], static::$maxSocketNameLength) . " "
                    . str_pad((string)$info['name'], static::$maxWorkerNameLength) . " "
                    . str_pad('N/A', 11) . " " . str_pad('N/A', 9) . " "
                    . str_pad('N/A', 7) . " " . str_pad('N/A', 13) . " N/A    [busy] \n";
                continue;
            }
            //$qps = isset($totalRequestCache[$pid]) ? $currentTotalRequest[$pid]
            if (!isset($totalRequestCache[$pid]) || !isset($currentTotalRequest[$pid])) {
                $qps = 0;
            } else {
                $qps = $currentTotalRequest[$pid] - $totalRequestCache[$pid];
                $totalQps += $qps;
            }
            $statusStr .= $dataWaitingSort[$pid] . " " . str_pad((string)$qps, 6) . " [idle]\n";
        }
        $totalRequestCache = $currentTotalRequest;
        $statusStr .= "----------------------------------------------PROCESS STATUS---------------------------------------------------\n";
        $statusStr .= "Summary\t" . str_pad($totalMemory . 'M', 7) . " "
            . str_pad('-', $maxLen1) . " "
            . str_pad('-', $maxLen2) . " "
            . str_pad((string)$totalConnections, 11) . " " . str_pad((string)$totalFails, 9) . " "
            . str_pad((string)$totalTimers, 7) . " " . str_pad((string)$totalRequests, 13) . " "
            . str_pad((string)$totalQps, 6) . " [Summary] \n";
        return $statusStr;
    }


    /**
     * Install signal handler.
     *
     * @return void
     */
    protected static function installSignal(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        $signals = [SIGINT, SIGTERM, SIGHUP, SIGTSTP, SIGQUIT, SIGUSR1, SIGUSR2, SIGIOT, SIGIO];
        foreach ($signals as $signal) {
            pcntl_signal($signal, [static::class, 'signalHandler'], false);
        }
        // ignore
        pcntl_signal(SIGPIPE, SIG_IGN, false);
    }

    /**
     * Reinstall signal handler.
     *
     * @return void
     * @throws Throwable
     */
    protected static function reinstallSignal(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        $signals = [SIGINT, SIGTERM, SIGHUP, SIGTSTP, SIGQUIT, SIGUSR1, SIGUSR2, SIGIOT, SIGIO];
        foreach ($signals as $signal) {
            pcntl_signal($signal, SIG_IGN, false);
            static::$globalEvent->onSignal($signal, [static::class, 'signalHandler']);
        }
    }

    /**
     * Signal handler.
     *
     * @param int $signal
     * @throws Exception
     */
    public static function signalHandler(int $signal): void
    {
        switch ($signal) {
            // Stop.
            case SIGINT:
            case SIGTERM:
            case SIGHUP:
            case SIGTSTP:
                static::$gracefulStop = false;
                static::stopAll();
                break;
            // Graceful stop.
            case SIGQUIT:
                static::$gracefulStop = true;
                static::stopAll();
                break;
            // Reload.
            case SIGUSR2:
            case SIGUSR1:
                if (static::$status === static::STATUS_RELOADING || static::$status === static::STATUS_SHUTDOWN) {
                    return;
                }
                static::$gracefulStop = $signal === SIGUSR2;
                static::$pidsToRestart = static::getAllWorkerPids();
                static::reload();
                break;
            // Show status.
            case SIGIOT:
                static::writeStatisticsToStatusFile();
                break;
            // Show connection status.
            case SIGIO:
                static::writeConnectionsStatisticsToStatusFile();
                break;
        }
    }

    /**
     * Run as daemon mode.
     *
     * @throws Exception
     */
    protected static function daemonize(): void
    {
        if (!static::$daemonize || DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        umask(0);
        $pid = pcntl_fork();
        if (-1 === $pid) {
            throw new RuntimeException('Fork fail');
        } elseif ($pid > 0) {
            exit(0);
        }
        if (-1 === posix_setsid()) {
            throw new RuntimeException("Setsid fail");
        }
        // Fork again avoid SVR4 system regain the control of terminal.
        $pid = pcntl_fork();
        if (-1 === $pid) {
            throw new RuntimeException("Fork fail");
        } elseif (0 !== $pid) {
            exit(0);
        }
    }

    /**
     * Redirect standard input and output.
     *
     * @param bool $throwException
     * @return void
     * @throws Exception
     */
    public static function resetStd(bool $throwException = true): void
    {
        if (!static::$daemonize || DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        global $STDOUT, $STDERR;
        $handle = fopen(static::$stdoutFile, "a");
        if ($handle) {
            unset($handle);
            set_error_handler(function () {
            });
            if ($STDOUT) {
                fclose($STDOUT);
            }
            if ($STDERR) {
                fclose($STDERR);
            }
            if (is_resource(STDOUT)) {
                fclose(STDOUT);
            }
            if (is_resource(STDERR)) {
                fclose(STDERR);
            }
            $STDOUT = fopen(static::$stdoutFile, "a");
            $STDERR = fopen(static::$stdoutFile, "a");
            // Fix standard output cannot redirect of PHP 8.1.8's bug
            if (function_exists('posix_isatty') && posix_isatty(2)) {
                ob_start(function ($string) {
                    file_put_contents(static::$stdoutFile, $string, FILE_APPEND);
                }, 1);
            }
            // change output stream
            static::$outputStream = null;
            static::outputStream($STDOUT);
            restore_error_handler();
            return;
        }
        if ($throwException) {
            throw new RuntimeException('Can not open stdoutFile ' . static::$stdoutFile);
        }
    }

    /**
     * Save pid.
     *
     * @throws Exception
     */
    protected static function saveMasterPid(): void
    {
        global $argv;
        if (DIRECTORY_SEPARATOR !== '/' || empty($argv)) {
            return;
        }

        static::$masterPid = posix_getpid();
        if (false === file_put_contents(static::$pidFile, static::$masterPid)) {
            throw new RuntimeException('can not save pid to ' . static::$pidFile);
        }
    }

    /**
     * Get event loop name.
     *
     * @return string
     */
    protected static function getEventLoopName(): string
    {
        if (static::$eventLoopClass) {
            return static::$eventLoopClass;
        }

        if (class_exists(EventLoop::class)) {
            static::$eventLoopClass = Revolt::class;
            return static::$eventLoopClass;
        }

        $loopName = '';
        foreach (static::$availableEventLoops as $name => $class) {
            if (extension_loaded($name)) {
                $loopName = $name;
                break;
            }
        }

        if ($loopName) {
            static::$eventLoopClass = static::$availableEventLoops[$loopName];
        } else {
            static::$eventLoopClass = Select::class;
        }
        return static::$eventLoopClass;
    }

    /**
     * Get all pids of worker processes.
     *
     * @return array
     */
    protected static function getAllWorkerPids(): array
    {
        $pidArray = [];
        foreach (static::$pidMap as $workerPidArray) {
            foreach ($workerPidArray as $workerPid) {
                $pidArray[$workerPid] = $workerPid;
            }
        }
        return $pidArray;
    }

    /**
     * Fork some worker processes.
     *
     * @return void
     * @throws Throwable
     */
    protected static function forkWorkers(): void
    {
        if (DIRECTORY_SEPARATOR === '/') {
            static::forkWorkersForLinux();
        } else {
            static::forkWorkersForWindows();
        }
    }

    /**
     * Fork some worker processes.
     *
     * @return void
     * @throws Throwable
     */
    protected static function forkWorkersForLinux(): void
    {
        foreach (static::$workers as $worker) {
            if (static::$status === static::STATUS_STARTING) {
                if (empty($worker->name)) {
                    $worker->name = $worker->getSocketName();
                }
                $workerNameLength = strlen($worker->name);
                if (static::$maxWorkerNameLength < $workerNameLength) {
                    static::$maxWorkerNameLength = $workerNameLength;
                }
            }

            while (count(static::$pidMap[$worker->workerId]) < $worker->count) {
                static::forkOneWorkerForLinux($worker);
            }
        }
    }

    /**
     * Fork some worker processes.
     *
     * @return void
     * @throws Throwable
     */
    protected static function forkWorkersForWindows(): void
    {
        $files = static::getStartFilesForWindows();
        if (in_array('-q', static::getArgv()) || count($files) === 1) {
            if (count(static::$workers) > 1) {
                static::safeEcho("@@@ Error: multi workers init in one php file are not support @@@\r\n");
                static::safeEcho("@@@ See https://www.workerman.net/doc/workerman/faq/multi-woker-for-windows.html @@@\r\n");
            } elseif (count(static::$workers) <= 0) {
                exit("@@@no worker inited@@@\r\n\r\n");
            }

            reset(static::$workers);
            /** @var Worker $worker */
            $worker = current(static::$workers);

            Timer::delAll();

            //Update process state.
            static::$status = static::STATUS_RUNNING;

            // Register shutdown function for checking errors.
            register_shutdown_function([__CLASS__, 'checkErrors']);

            // Create a global event loop.
            if (!static::$globalEvent) {
                $eventLoopClass = static::getEventLoopName();
                static::$globalEvent = new $eventLoopClass;
                static::$globalEvent->setErrorHandler(function ($exception) {
                    static::stopAll(250, $exception);
                });
            }

            // Reinstall signal.
            static::reinstallSignal();

            // Init Timer.
            Timer::init(static::$globalEvent);

            restore_error_handler();

            // Display UI.
            static::safeEcho(str_pad($worker->name, 21) . str_pad($worker->getSocketName(), 36) . str_pad((string)$worker->count, 10) . "[ok]\n");
            $worker->listen();
            $worker->run();
            static::$globalEvent->run();
            if (static::$status !== self::STATUS_SHUTDOWN) {
                $err = new Exception('event-loop exited');
                static::log($err);
                exit(250);
            }
            exit(0);
        } else {
            static::$globalEvent = new Select();
            static::$globalEvent->setErrorHandler(function ($exception) {
                static::stopAll(250, $exception);
            });
            Timer::init(static::$globalEvent);
            foreach ($files as $startFile) {
                static::forkOneWorkerForWindows($startFile);
            }
        }
    }

    /**
     * Get start files for windows.
     *
     * @return array
     */
    public static function getStartFilesForWindows(): array
    {
        $files = [];
        foreach (static::getArgv() as $file) {
            if (is_file($file)) {
                $files[$file] = $file;
            }
        }
        return $files;
    }

    /**
     * Fork one worker process.
     *
     * @param string $startFile
     */
    public static function forkOneWorkerForWindows(string $startFile): void
    {
        $startFile = realpath($startFile);

        $descriptor_spec = array(
            STDIN, STDOUT, STDOUT
        );

        $pipes = array();
        $process = proc_open('"' . PHP_BINARY . '" ' . " \"$startFile\" -q", $descriptor_spec, $pipes, null, null, ['bypass_shell' => true]);

        if (empty(static::$globalEvent)) {
            static::$globalEvent = new Select();
            static::$globalEvent->setErrorHandler(function ($exception) {
                static::stopAll(250, $exception);
            });
            Timer::init(static::$globalEvent);
        }

        // 保存子进程句柄
        static::$processForWindows[$startFile] = array($process, $startFile);
    }

    /**
     * check worker status for windows.
     * @return void
     */
    public static function checkWorkerStatusForWindows(): void
    {
        foreach (static::$processForWindows as $processData) {
            $process = $processData[0];
            $startFile = $processData[1];
            $status = proc_get_status($process);
            if (isset($status['running'])) {
                if (!$status['running']) {
                    static::safeEcho("process $startFile terminated and try to restart\n");
                    proc_close($process);
                    static::forkOneWorkerForWindows($startFile);
                }
            } else {
                static::safeEcho("proc_get_status fail\n");
            }
        }
    }

    /**
     * Fork one worker process.
     *
     * @param self $worker
     * @throws Exception|Throwable
     */
    protected static function forkOneWorkerForLinux(self $worker): void
    {
        // Get available worker id.
        $id = static::getId($worker->workerId, 0);
        $pid = pcntl_fork();
        // For master process.
        if ($pid > 0) {
            static::$pidMap[$worker->workerId][$pid] = $pid;
            static::$idMap[$worker->workerId][$id] = $pid;
        } // For child processes.
        elseif (0 === $pid) {
            srand();
            mt_srand();
            static::$gracefulStop = false;
            if (static::$status === static::STATUS_STARTING) {
                static::resetStd();
            }
            static::$pidsToRestart = static::$pidMap = [];
            // Remove other listener.
            foreach (static::$workers as $key => $oneWorker) {
                if ($oneWorker->workerId !== $worker->workerId) {
                    $oneWorker->unlisten();
                    unset(static::$workers[$key]);
                }
            }
            Timer::delAll();

            //Update process state.
            static::$status = static::STATUS_RUNNING;

            // Register shutdown function for checking errors.
            register_shutdown_function(["\\Workerman\\Worker", 'checkErrors']);

            // Create a global event loop.
            if (!static::$globalEvent) {
                $eventLoopClass = static::getEventLoopName();
                static::$globalEvent = new $eventLoopClass;
                static::$globalEvent->setErrorHandler(function ($exception) {
                    static::stopAll(250, $exception);
                });
            }

            // Reinstall signal.
            static::reinstallSignal();

            // Init Timer.
            Timer::init(static::$globalEvent);

            restore_error_handler();

            static::setProcessTitle(self::$processTitle . ': worker process  ' . $worker->name . ' ' . $worker->getSocketName());
            $worker->setUserAndGroup();
            $worker->id = $id;
            $worker->run();
            // Main loop.
            static::$globalEvent->run();
            if (static::$status !== self::STATUS_SHUTDOWN) {
                $err = new Exception('event-loop exited');
                static::log($err);
                exit(250);
            }
            exit(0);
        } else {
            throw new RuntimeException("forkOneWorker fail");
        }
    }

    /**
     * Get worker id.
     *
     * @param string $workerId
     * @param int $pid
     *
     * @return false|int|string
     */
    protected static function getId(string $workerId, int $pid): bool|int|string
    {
        return array_search($pid, static::$idMap[$workerId]);
    }

    /**
     * Set unix user and group for current process.
     *
     * @return void
     */
    public function setUserAndGroup(): void
    {
        // Get uid.
        $userInfo = posix_getpwnam($this->user);
        if (!$userInfo) {
            static::log("Warning: User $this->user not exists");
            return;
        }
        $uid = $userInfo['uid'];
        // Get gid.
        if ($this->group) {
            $groupInfo = posix_getgrnam($this->group);
            if (!$groupInfo) {
                static::log("Warning: Group $this->group not exists");
                return;
            }
            $gid = $groupInfo['gid'];
        } else {
            $gid = $userInfo['gid'];
        }

        // Set uid and gid.
        if ($uid !== posix_getuid() || $gid !== posix_getgid()) {
            if (!posix_setgid($gid) || !posix_initgroups($userInfo['name'], $gid) || !posix_setuid($uid)) {
                static::log("Warning: change gid or uid fail.");
            }
        }
    }

    /**
     * Set process name.
     *
     * @param string $title
     * @return void
     */
    protected static function setProcessTitle(string $title): void
    {
        set_error_handler(function () {
        });
        cli_set_process_title($title);
        restore_error_handler();
    }

    /**
     * Monitor all child processes.
     *
     * @return void
     * @throws Throwable
     */
    protected static function monitorWorkers(): void
    {
        if (DIRECTORY_SEPARATOR === '/') {
            static::monitorWorkersForLinux();
        } else {
            static::monitorWorkersForWindows();
        }
    }

    /**
     * Monitor all child processes.
     *
     * @return void
     * @throws Throwable
     */
    protected static function monitorWorkersForLinux(): void
    {
        static::$status = static::STATUS_RUNNING;
        while (1) {
            // Calls signal handlers for pending signals.
            pcntl_signal_dispatch();
            // Suspends execution of the current process until a child has exited, or until a signal is delivered
            $status = 0;
            $pid = pcntl_wait($status, WUNTRACED);
            // Calls signal handlers for pending signals again.
            pcntl_signal_dispatch();
            // If a child has already exited.
            if ($pid > 0) {
                // Find out which worker process exited.
                foreach (static::$pidMap as $workerId => $workerPidArray) {
                    if (isset($workerPidArray[$pid])) {
                        $worker = static::$workers[$workerId];
                        // Fix exit with status 2 for php8.2
                        if ($status === SIGINT && static::$status === static::STATUS_SHUTDOWN) {
                            $status = 0;
                        }
                        // Exit status.
                        if ($status !== 0) {
                            static::log("worker[$worker->name:$pid] exit with status $status");
                        }

                        // onWorkerExit
                        if (static::$onWorkerExit) {
                            try {
                                (static::$onWorkerExit)($worker, $status, $pid);
                            } catch (Throwable $exception) {
                                static::log("worker[$worker->name] onWorkerExit $exception");
                            }
                        }

                        // For Statistics.
                        if (!isset(static::$globalStatistics['worker_exit_info'][$workerId][$status])) {
                            static::$globalStatistics['worker_exit_info'][$workerId][$status] = 0;
                        }
                        ++static::$globalStatistics['worker_exit_info'][$workerId][$status];

                        // Clear process data.
                        unset(static::$pidMap[$workerId][$pid]);

                        // Mark id is available.
                        $id = static::getId($workerId, $pid);
                        static::$idMap[$workerId][$id] = 0;

                        break;
                    }
                }
                // Is still running state then fork a new worker process.
                if (static::$status !== static::STATUS_SHUTDOWN) {
                    static::forkWorkers();
                    // If reloading continue.
                    if (isset(static::$pidsToRestart[$pid])) {
                        unset(static::$pidsToRestart[$pid]);
                        static::reload();
                    }
                }
            }

            // If shutdown state and all child processes exited then master process exit.
            if (static::$status === static::STATUS_SHUTDOWN && !static::getAllWorkerPids()) {
                static::exitAndClearAll();
            }
        }
    }

    /**
     * Monitor all child processes.
     *
     * @return void
     * @throws Throwable
     */
    protected static function monitorWorkersForWindows(): void
    {
        Timer::add(1, "\\Workerman\\Worker::checkWorkerStatusForWindows");

        static::$globalEvent->run();
    }

    /**
     * Exit current process.
     */
    protected static function exitAndClearAll(): void
    {
        foreach (static::$workers as $worker) {
            $socketName = $worker->getSocketName();
            if ($worker->transport === 'unix' && $socketName) {
                list(, $address) = explode(':', $socketName, 2);
                $address = substr($address, strpos($address, '/') + 2);
                @unlink($address);
            }
        }
        @unlink(static::$pidFile);
        static::log("Workerman[" . basename(static::$startFile) . "] has been stopped");
        if (static::$onMasterStop) {
            call_user_func(static::$onMasterStop);
        }
        exit(0);
    }

    /**
     * Execute reload.
     *
     * @return void
     * @throws Exception
     */
    protected static function reload(): void
    {
        // For master process.
        if (static::$masterPid === posix_getpid()) {
            $sig = static::$gracefulStop ? SIGUSR2 : SIGUSR1;
            // Set reloading state.
            if (static::$status !== static::STATUS_RELOADING && static::$status !== static::STATUS_SHUTDOWN) {
                static::log("Workerman[" . basename(static::$startFile) . "] reloading");
                static::$status = static::STATUS_RELOADING;

                static::resetStd(false);
                // Try to emit onMasterReload callback.
                if (static::$onMasterReload) {
                    try {
                        call_user_func(static::$onMasterReload);
                    } catch (Throwable $e) {
                        static::stopAll(250, $e);
                    }
                    static::initId();
                }

                // Send reload signal to all child processes.
                $reloadablePidArray = [];
                foreach (static::$pidMap as $workerId => $workerPidArray) {
                    $worker = static::$workers[$workerId];
                    if ($worker->reloadable) {
                        foreach ($workerPidArray as $pid) {
                            $reloadablePidArray[$pid] = $pid;
                        }
                    } else {
                        foreach ($workerPidArray as $pid) {
                            // Send reload signal to a worker process which reloadable is false.
                            posix_kill($pid, $sig);
                        }
                    }
                }
                // Get all pids that are waiting reload.
                static::$pidsToRestart = array_intersect(static::$pidsToRestart, $reloadablePidArray);
            }

            // Reload complete.
            if (empty(static::$pidsToRestart)) {
                if (static::$status !== static::STATUS_SHUTDOWN) {
                    static::$status = static::STATUS_RUNNING;
                }
                return;
            }
            // Continue reload.
            $oneWorkerPid = current(static::$pidsToRestart);
            // Send reload signal to a worker process.
            posix_kill($oneWorkerPid, $sig);
            // If the process does not exit after stopTimeout seconds try to kill it.
            if (!static::$gracefulStop) {
                Timer::add(static::$stopTimeout, '\posix_kill', [$oneWorkerPid, SIGKILL], false);
            }
        } // For child processes.
        else {
            reset(static::$workers);
            $worker = current(static::$workers);
            // Try to emit onWorkerReload callback.
            if ($worker->onWorkerReload) {
                try {
                    call_user_func($worker->onWorkerReload, $worker);
                } catch (Throwable $e) {
                    static::stopAll(250, $e);
                }
            }

            if ($worker->reloadable) {
                static::stopAll();
            } else {
                static::resetStd(false);
            }
        }
    }

    /**
     * Stop all.
     *
     * @param int $code
     * @param mixed $log
     */
    public static function stopAll(int $code = 0, mixed $log = ''): void
    {
        if ($log) {
            static::log($log);
        }

        static::$status = static::STATUS_SHUTDOWN;
        // For master process.
        if (DIRECTORY_SEPARATOR === '/' && static::$masterPid === posix_getpid()) {
            static::log("Workerman[" . basename(static::$startFile) . "] stopping ...");
            $workerPidArray = static::getAllWorkerPids();
            // Send stop signal to all child processes.
            $sig = static::$gracefulStop ? SIGQUIT : SIGINT;
            foreach ($workerPidArray as $workerPid) {
                // Fix exit with status 2 for php8.2
                if ($sig === SIGINT && !static::$daemonize) {
                    Timer::add(1, '\posix_kill', [$workerPid, SIGINT], false);
                } else {
                    posix_kill($workerPid, $sig);
                }
                if (!static::$gracefulStop) {
                    Timer::add(ceil(static::$stopTimeout), '\posix_kill', [$workerPid, SIGKILL], false);
                }
            }
            Timer::add(1, "\\Workerman\\Worker::checkIfChildRunning");
            // Remove statistics file.
            if (is_file(static::$statisticsFile)) {
                @unlink(static::$statisticsFile);
            }
        } // For child processes.
        else {
            // Execute exit.
            $workers = array_reverse(static::$workers);
            foreach ($workers as $worker) {
                if (!$worker->stopping) {
                    $worker->stop();
                    $worker->stopping = true;
                }
            }
            if (!static::$gracefulStop || ConnectionInterface::$statistics['connection_count'] <= 0) {
                static::$workers = [];
                static::$globalEvent?->stop();

                try {
                    exit($code);
                } catch (Exception $e) {

                }
            }
        }
    }

    /**
     * check if child processes is really running
     */
    public static function checkIfChildRunning(): void
    {
        foreach (static::$pidMap as $workerId => $workerPidArray) {
            foreach ($workerPidArray as $pid => $workerPid) {
                if (!posix_kill($pid, 0)) {
                    unset(static::$pidMap[$workerId][$pid]);
                }
            }
        }
    }

    /**
     * Get process status.
     *
     * @return int
     */
    public static function getStatus(): int
    {
        return static::$status;
    }

    /**
     * If stop gracefully.
     *
     * @return bool
     */
    public static function getGracefulStop(): bool
    {
        return static::$gracefulStop;
    }

    /**
     * Write statistics data to disk.
     *
     * @return void
     */
    protected static function writeStatisticsToStatusFile(): void
    {
        // For master process.
        if (static::$masterPid === posix_getpid()) {
            $allWorkerInfo = [];
            foreach (static::$pidMap as $workerId => $pidArray) {
                /** @var /Workerman/Worker $worker */
                $worker = static::$workers[$workerId];
                foreach ($pidArray as $pid) {
                    $allWorkerInfo[$pid] = ['name' => $worker->name, 'listen' => $worker->getSocketName()];
                }
            }

            file_put_contents(static::$statisticsFile, serialize($allWorkerInfo) . "\n", FILE_APPEND);
            $loadavg = function_exists('sys_getloadavg') ? array_map('round', sys_getloadavg(), [2, 2, 2]) : ['-', '-', '-'];
            file_put_contents(static::$statisticsFile,
                "----------------------------------------------GLOBAL STATUS----------------------------------------------------\n", FILE_APPEND);
            file_put_contents(static::$statisticsFile,
                'Workerman version:' . static::VERSION . "          PHP version:" . PHP_VERSION . "\n", FILE_APPEND);
            file_put_contents(static::$statisticsFile, 'start time:' . date('Y-m-d H:i:s',
                    static::$globalStatistics['start_timestamp']) . '   run ' . floor((time() - static::$globalStatistics['start_timestamp']) / (24 * 60 * 60)) . ' days ' . floor(((time() - static::$globalStatistics['start_timestamp']) % (24 * 60 * 60)) / (60 * 60)) . " hours   \n",
                FILE_APPEND);
            $loadStr = 'load average: ' . implode(", ", $loadavg);
            file_put_contents(static::$statisticsFile,
                str_pad($loadStr, 33) . 'event-loop:' . static::getEventLoopName() . "\n", FILE_APPEND);
            file_put_contents(static::$statisticsFile,
                count(static::$pidMap) . ' workers       ' . count(static::getAllWorkerPids()) . " processes\n",
                FILE_APPEND);
            file_put_contents(static::$statisticsFile,
                str_pad('worker_name', static::$maxWorkerNameLength) . " exit_status      exit_count\n", FILE_APPEND);
            foreach (static::$pidMap as $workerId => $workerPidArray) {
                $worker = static::$workers[$workerId];
                if (isset(static::$globalStatistics['worker_exit_info'][$workerId])) {
                    foreach (static::$globalStatistics['worker_exit_info'][$workerId] as $workerExitStatus => $workerExitCount) {
                        file_put_contents(static::$statisticsFile,
                            str_pad($worker->name, static::$maxWorkerNameLength) . " " . str_pad((string)$workerExitStatus,
                                16) . " $workerExitCount\n", FILE_APPEND);
                    }
                } else {
                    file_put_contents(static::$statisticsFile,
                        str_pad($worker->name, static::$maxWorkerNameLength) . " " . str_pad('0', 16) . " 0\n",
                        FILE_APPEND);
                }
            }
            file_put_contents(static::$statisticsFile,
                "----------------------------------------------PROCESS STATUS---------------------------------------------------\n",
                FILE_APPEND);
            file_put_contents(static::$statisticsFile,
                "pid\tmemory  " . str_pad('listening', static::$maxSocketNameLength) . " " . str_pad('worker_name',
                    static::$maxWorkerNameLength) . " connections " . str_pad('send_fail', 9) . " "
                . str_pad('timers', 8) . str_pad('total_request', 13) . " qps    status\n", FILE_APPEND);

            chmod(static::$statisticsFile, 0722);

            foreach (static::getAllWorkerPids() as $workerPid) {
                posix_kill($workerPid, SIGIOT);
            }
            return;
        }

        // For child processes.
        gc_collect_cycles();
        if (function_exists('gc_mem_caches')) {
            gc_mem_caches();
        }
        reset(static::$workers);
        /** @var static $worker */
        $worker = current(static::$workers);
        $workerStatusStr = posix_getpid() . "\t" . str_pad(round(memory_get_usage() / (1024 * 1024), 2) . "M", 7)
            . " " . str_pad($worker->getSocketName(), static::$maxSocketNameLength) . " "
            . str_pad(($worker->name === $worker->getSocketName() ? 'none' : $worker->name), static::$maxWorkerNameLength)
            . " ";
        $workerStatusStr .= str_pad((string)ConnectionInterface::$statistics['connection_count'], 11)
            . " " . str_pad((string)ConnectionInterface::$statistics['send_fail'], 9)
            . " " . str_pad((string)static::$globalEvent->getTimerCount(), 7)
            . " " . str_pad((string)ConnectionInterface::$statistics['total_request'], 13) . "\n";
        file_put_contents(static::$statisticsFile, $workerStatusStr, FILE_APPEND);
    }

    /**
     * Write statistics data to disk.
     *
     * @return void
     */
    protected static function writeConnectionsStatisticsToStatusFile(): void
    {
        // For master process.
        if (static::$masterPid === posix_getpid()) {
            file_put_contents(static::$statisticsFile, "--------------------------------------------------------------------- WORKERMAN CONNECTION STATUS --------------------------------------------------------------------------------\n", FILE_APPEND);
            file_put_contents(static::$statisticsFile, "PID      Worker          CID       Trans   Protocol        ipv4   ipv6   Recv-Q       Send-Q       Bytes-R      Bytes-W       Status         Local Address          Foreign Address\n", FILE_APPEND);
            chmod(static::$statisticsFile, 0722);
            foreach (static::getAllWorkerPids() as $workerPid) {
                posix_kill($workerPid, SIGIO);
            }
            return;
        }

        // For child processes.
        $bytesFormat = function ($bytes) {
            if ($bytes > 1024 * 1024 * 1024 * 1024) {
                return round($bytes / (1024 * 1024 * 1024 * 1024), 1) . "TB";
            }
            if ($bytes > 1024 * 1024 * 1024) {
                return round($bytes / (1024 * 1024 * 1024), 1) . "GB";
            }
            if ($bytes > 1024 * 1024) {
                return round($bytes / (1024 * 1024), 1) . "MB";
            }
            if ($bytes > 1024) {
                return round($bytes / (1024), 1) . "KB";
            }
            return $bytes . "B";
        };

        $pid = posix_getpid();
        $str = '';
        reset(static::$workers);
        $currentWorker = current(static::$workers);
        $defaultWorkerName = $currentWorker->name;

        /** @var static $worker */
        foreach (TcpConnection::$connections as $connection) {
            /** @var TcpConnection $connection */
            $transport = $connection->transport;
            $ipv4 = $connection->isIpV4() ? ' 1' : ' 0';
            $ipv6 = $connection->isIpV6() ? ' 1' : ' 0';
            $recvQ = $bytesFormat($connection->getRecvBufferQueueSize());
            $sendQ = $bytesFormat($connection->getSendBufferQueueSize());
            $localAddress = trim($connection->getLocalAddress());
            $remoteAddress = trim($connection->getRemoteAddress());
            $state = $connection->getStatus(false);
            $bytesRead = $bytesFormat($connection->bytesRead);
            $bytesWritten = $bytesFormat($connection->bytesWritten);
            $id = $connection->id;
            $protocol = $connection->protocol ?: $connection->transport;
            $pos = strrpos($protocol, '\\');
            if ($pos) {
                $protocol = substr($protocol, $pos + 1);
            }
            if (strlen($protocol) > 15) {
                $protocol = substr($protocol, 0, 13) . '..';
            }
            $workerName = isset($connection->worker) ? $connection->worker->name : $defaultWorkerName;
            if (strlen($workerName) > 14) {
                $workerName = substr($workerName, 0, 12) . '..';
            }
            $str .= str_pad((string)$pid, 9) . str_pad($workerName, 16) . str_pad((string)$id, 10) . str_pad($transport, 8)
                . str_pad($protocol, 16) . str_pad($ipv4, 7) . str_pad($ipv6, 7) . str_pad($recvQ, 13)
                . str_pad($sendQ, 13) . str_pad($bytesRead, 13) . str_pad($bytesWritten, 13) . ' '
                . str_pad($state, 14) . ' ' . str_pad($localAddress, 22) . ' ' . str_pad($remoteAddress, 22) . "\n";
        }
        if ($str) {
            file_put_contents(static::$statisticsFile, $str, FILE_APPEND);
        }
    }

    /**
     * Check errors when current process exited.
     *
     * @return void
     */
    public static function checkErrors(): void
    {
        if (static::STATUS_SHUTDOWN !== static::$status) {
            $errorMsg = DIRECTORY_SEPARATOR === '/' ? 'Worker[' . posix_getpid() . '] process terminated' : 'Worker process terminated';
            $errors = error_get_last();
            if ($errors && ($errors['type'] === E_ERROR ||
                    $errors['type'] === E_PARSE ||
                    $errors['type'] === E_CORE_ERROR ||
                    $errors['type'] === E_COMPILE_ERROR ||
                    $errors['type'] === E_RECOVERABLE_ERROR)
            ) {
                $errorMsg .= ' with ERROR: ' . static::getErrorType($errors['type']) . " \"{$errors['message']} in {$errors['file']} on line {$errors['line']}\"";
            }
            static::log($errorMsg);
        }
    }

    /**
     * Get error message by error code.
     *
     * @param int $type
     * @return string
     */
    protected static function getErrorType(int $type): string
    {
        return self::ERROR_TYPE[$type] ?? '';
    }

    /**
     * Log.
     *
     * @param mixed $msg
     * @param bool $decorated
     * @return void
     */
    public static function log(mixed $msg, bool $decorated = false): void
    {
        $msg = $msg . "\n";
        if (!static::$daemonize) {
            static::safeEcho($msg, $decorated);
        }
        file_put_contents(static::$logFile, date('Y-m-d H:i:s') . ' ' . 'pid:'
            . (DIRECTORY_SEPARATOR === '/' ? posix_getpid() : 1) . ' ' . $msg, FILE_APPEND | LOCK_EX);
    }

    /**
     * Safe Echo.
     * @param string $msg
     * @param bool $decorated
     * @return bool
     */
    public static function safeEcho(string $msg, bool $decorated = false): bool
    {
        $stream = static::outputStream();
        if (!$stream) {
            return false;
        }
        if (!$decorated) {
            $line = $white = $green = $end = '';
            if (static::$outputDecorated) {
                $line = "\033[1A\n\033[K";
                $white = "\033[47;30m";
                $green = "\033[32;40m";
                $end = "\033[0m";
            }
            $msg = str_replace(['<n>', '<w>', '<g>'], [$line, $white, $green], $msg);
            $msg = str_replace(['</n>', '</w>', '</g>'], $end, $msg);
        } elseif (!static::$outputDecorated) {
            return false;
        }
        fwrite($stream, $msg);
        fflush($stream);
        return true;
    }

    /**
     * set and get output stream.
     *
     * @param resource|null $stream
     * @return false|resource
     */
    private static function outputStream($stream = null)
    {
        if (!$stream) {
            $stream = static::$outputStream ?: STDOUT;
        }
        if (!$stream || !is_resource($stream) || 'stream' !== get_resource_type($stream)) {
            return false;
        }
        $stat = fstat($stream);
        if (!$stat) {
            return false;
        }

        if (($stat['mode'] & 0170000) === 0100000) { // whether is regular file
            static::$outputDecorated = false;
        } else {
            static::$outputDecorated =
                DIRECTORY_SEPARATOR === '/' && // linux or unix
                function_exists('posix_isatty') &&
                posix_isatty($stream); // whether is interactive terminal
        }
        return static::$outputStream = $stream;
    }

    /**
     * Constructor.
     *
     * @param string|null $socketName
     * @param array $socketContext
     */
    public function __construct(string $socketName = null, array $socketContext = [])
    {
        // Save all worker instances.
        $this->workerId = spl_object_hash($this);
        $this->context = new stdClass();
        static::$workers[$this->workerId] = $this;
        static::$pidMap[$this->workerId] = [];

        // Context for socket.
        if ($socketName) {
            $this->socketName = $socketName;
            if (!isset($socketContext['socket']['backlog'])) {
                $socketContext['socket']['backlog'] = static::DEFAULT_BACKLOG;
            }
            $this->socketContext = stream_context_create($socketContext);
        }

        // Try to turn reusePort on.
        /*if (\DIRECTORY_SEPARATOR === '/'  // if linux
            && $socketName
            && \version_compare(php_uname('r'), '3.9', 'ge') // if kernel >=3.9
            && \strtolower(\php_uname('s')) !== 'darwin' // if not Mac OS
            && strpos($socketName,'unix') !== 0 // if not unix socket
            && strpos($socketName,'udp') !== 0) { // if not udp socket
            
            $address = \parse_url($socketName);
            if (isset($address['host']) && isset($address['port'])) {
                try {
                    \set_error_handler(function(){});
                    // If address not in use, turn reusePort on automatically.
                    $server = stream_socket_server("tcp://{$address['host']}:{$address['port']}");
                    if ($server) {
                        $this->reusePort = true;
                        fclose($server);
                    }
                    \restore_error_handler();
                } catch (\Throwable $e) {}
            }
        }*/
    }

    /**
     * Listen.
     *
     * @throws Exception
     */
    public function listen(): void
    {
        if (!$this->socketName) {
            return;
        }

        if (!$this->mainSocket) {

            $localSocket = $this->parseSocketAddress();

            // Flag.
            $flags = $this->transport === 'udp' ? STREAM_SERVER_BIND : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
            $errno = 0;
            $errmsg = '';
            // SO_REUSEPORT.
            if ($this->reusePort) {
                stream_context_set_option($this->socketContext, 'socket', 'so_reuseport', 1);
            }

            // Create an Internet or Unix domain server socket.
            $this->mainSocket = stream_socket_server($localSocket, $errno, $errmsg, $flags, $this->socketContext);
            if (!$this->mainSocket) {
                throw new Exception($errmsg);
            }

            if ($this->transport === 'ssl') {
                stream_socket_enable_crypto($this->mainSocket, false);
            } elseif ($this->transport === 'unix') {
                $socketFile = substr($localSocket, 7);
                if ($this->user) {
                    chown($socketFile, $this->user);
                }
                if ($this->group) {
                    chgrp($socketFile, $this->group);
                }
            }

            // Try to open keepalive for tcp and disable Nagle algorithm.
            if (function_exists('socket_import_stream') && self::BUILD_IN_TRANSPORTS[$this->transport] === 'tcp') {
                set_error_handler(function () {
                });
                $socket = socket_import_stream($this->mainSocket);
                socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, 1);
                socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);
                restore_error_handler();
            }

            // Non blocking.
            stream_set_blocking($this->mainSocket, false);
        }

        $this->resumeAccept();
    }

    /**
     * Unlisten.
     *
     * @return void
     */
    public function unlisten(): void
    {
        $this->pauseAccept();
        if ($this->mainSocket) {
            set_error_handler(function () {
            });
            fclose($this->mainSocket);
            restore_error_handler();
            $this->mainSocket = null;
        }
    }

    /**
     * Parse local socket address.
     *
     * @throws Exception
     */
    protected function parseSocketAddress(): ?string
    {
        if (!$this->socketName) {
            return null;
        }
        // Get the application layer communication protocol and listening address.
        list($scheme, $address) = explode(':', $this->socketName, 2);
        // Check application layer protocol class.
        if (!isset(self::BUILD_IN_TRANSPORTS[$scheme])) {
            $scheme = ucfirst($scheme);
            $this->protocol = $scheme[0] === '\\' ? $scheme : 'Protocols\\' . $scheme;
            if (!class_exists($this->protocol)) {
                $this->protocol = "Workerman\\Protocols\\$scheme";
                if (!class_exists($this->protocol)) {
                    throw new RuntimeException("class \\Protocols\\$scheme not exist");
                }
            }

            if (!isset(self::BUILD_IN_TRANSPORTS[$this->transport])) {
                throw new RuntimeException('Bad worker->transport ' . var_export($this->transport, true));
            }
        } else if ($this->transport === 'tcp') {
            $this->transport = $scheme;
        }
        //local socket
        return self::BUILD_IN_TRANSPORTS[$this->transport] . ":" . $address;
    }

    /**
     * Pause accept new connections.
     *
     * @return void
     */
    public function pauseAccept(): void
    {
        if (static::$globalEvent && false === $this->pauseAccept && $this->mainSocket) {
            static::$globalEvent->offReadable($this->mainSocket);
            $this->pauseAccept = true;
        }
    }

    /**
     * Resume accept new connections.
     *
     * @return void
     */
    public function resumeAccept(): void
    {
        // Register a listener to be notified when server socket is ready to read.
        if (static::$globalEvent && true === $this->pauseAccept && $this->mainSocket) {
            if ($this->transport !== 'udp') {
                static::$globalEvent->onReadable($this->mainSocket, [$this, 'acceptTcpConnection']);
            } else {
                static::$globalEvent->onReadable($this->mainSocket, [$this, 'acceptUdpConnection']);
            }
            $this->pauseAccept = false;
        }
    }

    /**
     * Get socket name.
     *
     * @return string
     */
    public function getSocketName(): string
    {
        return $this->socketName ? lcfirst($this->socketName) : 'none';
    }

    /**
     * Run worker instance.
     *
     * @return void
     * @throws Throwable
     */
    public function run(): void
    {
        $this->listen();

        // Try to emit onWorkerStart callback.
        if ($this->onWorkerStart) {
            try {
                ($this->onWorkerStart)($this);
            } catch (Throwable $e) {
                // Avoid rapid infinite loop exit.
                sleep(1);
                static::stopAll(250, $e);
            }
        }
    }

    /**
     * Stop current worker instance.
     *
     * @return void
     */
    public function stop(): void
    {
        // Try to emit onWorkerStop callback.
        if ($this->onWorkerStop) {
            try {
                ($this->onWorkerStop)($this);
            } catch (Throwable $e) {
                static::log($e);
            }
        }
        // Remove listener for server socket.
        $this->unlisten();
        // Close all connections for the worker.
        if (!static::$gracefulStop) {
            foreach ($this->connections as $connection) {
                $connection->close();
            }
        }
        // Remove worker.
        foreach (static::$workers as $key => $one_worker) {
            if ($one_worker->workerId === $this->workerId) {
                unset(static::$workers[$key]);
            }
        }
        // Clear callback.
        $this->onMessage = $this->onClose = $this->onError = $this->onBufferDrain = $this->onBufferFull = null;
    }

    /**
     * Accept a connection.
     *
     * @param resource $socket
     * @return void
     */
    public function acceptTcpConnection($socket): void
    {
        // Accept a connection on server socket.
        set_error_handler(function () {
        });
        $newSocket = stream_socket_accept($socket, 0, $remoteAddress);
        restore_error_handler();

        // Thundering herd.
        if (!$newSocket) {
            return;
        }

        // TcpConnection.
        $connection = new TcpConnection(static::$globalEvent, $newSocket, $remoteAddress);
        $this->connections[$connection->id] = $connection;
        $connection->worker = $this;
        $connection->protocol = $this->protocol;
        $connection->transport = $this->transport;
        $connection->onMessage = $this->onMessage;
        $connection->onClose = $this->onClose;
        $connection->onError = $this->onError;
        $connection->onBufferDrain = $this->onBufferDrain;
        $connection->onBufferFull = $this->onBufferFull;

        // Try to emit onConnect callback.
        if ($this->onConnect) {
            try {
                ($this->onConnect)($connection);
            } catch (Throwable $e) {
                static::stopAll(250, $e);
            }
        }
    }

    /**
     * For udp package.
     *
     * @param resource $socket
     * @return bool
     */
    public function acceptUdpConnection($socket): bool
    {
        set_error_handler(function () {
        });
        $recvBuffer = stream_socket_recvfrom($socket, UdpConnection::MAX_UDP_PACKAGE_SIZE, 0, $remoteAddress);
        restore_error_handler();
        if (false === $recvBuffer || empty($remoteAddress)) {
            return false;
        }
        // UdpConnection.
        $connection = new UdpConnection($socket, $remoteAddress);
        $connection->protocol = $this->protocol;
        $messageCallback = $this->onMessage;
        if ($messageCallback) {
            try {
                if ($this->protocol !== null) {
                    /** @var ProtocolInterface $parser */
                    $parser = $this->protocol;
                    if ($parser && method_exists($parser, 'input')) {
                        while ($recvBuffer !== '') {
                            $len = $parser::input($recvBuffer, $connection);
                            if ($len === 0)
                                return true;
                            $package = substr($recvBuffer, 0, $len);
                            $recvBuffer = substr($recvBuffer, $len);
                            $data = $parser::decode($package, $connection);
                            if ($data === false) {
                                continue;
                            }
                            $messageCallback($connection, $data);
                        }
                    } else {
                        $data = $parser::decode($recvBuffer, $connection);
                        // Discard bad packets.
                        if ($data === false) {
                            return true;
                        }
                        $messageCallback($connection, $data);
                    }
                } else {
                    $messageCallback($connection, $recvBuffer);
                }
                ++ConnectionInterface::$statistics['total_request'];
            } catch (Throwable $e) {
                static::stopAll(250, $e);
            }
        }
        return true;
    }

    /**
     * Check master process is alive
     *
     * @param int $masterPid
     * @return bool
     */
    protected static function checkMasterIsAlive(int $masterPid): bool
    {
        if (empty($masterPid)) {
            return false;
        }

        $masterIsAlive = posix_kill($masterPid, 0) && posix_getpid() !== $masterPid;
        if (!$masterIsAlive) {
            return false;
        }

        $cmdline = "/proc/$masterPid/cmdline";
        if (!is_readable($cmdline) || empty(static::$processTitle)) {
            return true;
        }

        $content = file_get_contents($cmdline);
        if (empty($content)) {
            return true;
        }

        return stripos($content, static::$processTitle) !== false || stripos($content, 'php') !== false;
    }
}

