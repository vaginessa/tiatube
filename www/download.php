<?php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

const TIATUBE = '/opt/tiatube/tiatube.sh';

//decrease niceness
proc_nice(10);

function get_current_status()
{
    $status_tail = "";
    if ($_SESSION['done'] == false)
    {
        $running = is_session_process_running();

        $status_tail = file_get_contents($_SESSION['home'] . '/stderr', NULL , NULL, $_SESSION['status_lastpos']);
        $_SESSION['status_lastpos'] += strlen($status_tail);

        if (!$running)
        {
            $_SESSION['ret'] = intval(file_get_contents($_SESSION['home'] . '/ret'));
            $_SESSION['result_path'] = trim(file_get_contents($_SESSION['home'] . '/stdout'));
            $_SESSION['done'] = true;
        }
    }

    header('Content-Type: application/json');
    echo json_encode(array(
        'status-tail' => escape_newlines($status_tail),
        'ret' => $_SESSION['ret'],
        'done' => $_SESSION['done'],
    ));
}

function escape_newlines($text)
{
    return preg_replace('/\r?\n|\r|\n/','\\n', $text);
}

function start_download()
{
    global $video_id;
    $home = sys_get_temp_dir() . "/tiatube-" . $video_id . '-' . session_id();
    mkdir($home, 0770);

    $_SESSION = array(
        'video' => $video_id,
        'status_lastpos' => 0,
        'ret' => 0,
        'result_path' => "",
        'done' => false,
        'home' => $home,
        'cmd' => '(' . TIATUBE . ' ' . escapeshellarg($video_id) . '; echo $? > ' . $home . '/ret) & echo $! >' . $home . '/pid'
    );

    $descriptorspec = array(
        0 => array("pipe", "r"),
        1 => array("file", $home . '/stdout', "a"),
        2 => array("file", $home . '/stderr', "a"),
    );
    $env = array(
        'HOME' => $home,
    );
    $process = proc_open($_SESSION['cmd'], $descriptorspec, $pipes, NULL, $env);
    fclose($pipes[0]);

    #$status = proc_get_status($process);
    #$_SESSION['pid'] = $status['pid'];
    sleep(1);
    $_SESSION['pid'] = intval(file_get_contents($home . '/pid'));
}

function terminate_download()
{
    cleanup();
}

function stream_content()
{
    $file = get_path_of_first_mp3($_SESSION['result_path']);
    if (!$file)
    {
        http_response_code(404);
        exit("Missing converted file");
    }

    header("Content-Type: audio/mpeg, audio/x-mpeg, audio/x-mpeg-3, audio/mpeg3");
    header("Content-Transfer-Encoding: binary");
    header('Connection: Keep-Alive');
    header('Content-length: ' . filesize($file));
    header('X-Pad: avoid browser bug');

    if ($_GET['dl'] == "1")
    {
        header('Content-Description: File Transfer');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    }
    readfile($file);
    flush();

    cleanup();
}

function get_parameter($name)
{
    if (!isset($_GET[$name]))
    {
        http_response_code(400);
        exit("Missing a non-optional parameter \"$name\"");
    }
    return $_GET[$name];
}

function validate_video_id($video_id)
{
    if (preg_match("/[\/?&=]/", $video_id))
    {
        http_response_code(400);
        exit("Bad video ID \"$video_id\"");
    }
}

function cleanup()
{
    $running = is_session_process_running();
    if ($running)
        posix_kill($_SESSION['pid']);

    rrmdir($_SESSION['home']);
    rrmdir($_SESSION['result_path']);

    session_unset();
}

function is_session_process_running()
{
    $running = posix_getpgid($_SESSION['pid']);
    if (!$running)
        return false;
    $running = (stripos(get_command_by_pid($_SESSION['pid']), $_SESSION['cmd']) != false);
    return $running;
}

function get_command_by_pid($pid)
{
    return exec("ps -p $pid -o command=");
}

function rrmdir($dir)
{
    if (!is_dir($dir))
        return false;

    $objects = scandir($dir);
    foreach ($objects as $object)
    {
        if ($object == "." || $object == "..")
            continue;

        if (filetype($dir . "/" . $object) == "dir")
            rrmdir($dir . "/" . $object);
        else
            unlink($dir . "/" . $object);
    }
    reset($objects);
    rmdir($dir);
    return true;
}

function get_path_of_first_mp3($dir)
{
    var_dump($dir);
    if (!is_dir($dir))
        return false;

    $objects = scandir($dir);
    foreach ($objects as $object)
    {
        if ($object == "." || $object == ".." || !endsWith($object, '.mp3'))
            continue;

        if (filetype($dir . "/" . $object) != "dir")
            return $dir . "/" . $object;
    }
    reset($objects);
    return false;
}

function endsWith($haystack, $needle)
{
    return $needle === "" || substr($haystack, -strlen($needle)) === $needle;
}


if (!function_exists('http_response_code')) {
    function http_response_code($code = NULL) {
        if ($code !== NULL) {
            switch ($code) {
                case 100: $text = 'Continue'; break;
                case 101: $text = 'Switching Protocols'; break;
                case 200: $text = 'OK'; break;
                case 201: $text = 'Created'; break;
                case 202: $text = 'Accepted'; break;
                case 203: $text = 'Non-Authoritative Information'; break;
                case 204: $text = 'No Content'; break;
                case 205: $text = 'Reset Content'; break;
                case 206: $text = 'Partial Content'; break;
                case 300: $text = 'Multiple Choices'; break;
                case 301: $text = 'Moved Permanently'; break;
                case 302: $text = 'Moved Temporarily'; break;
                case 303: $text = 'See Other'; break;
                case 304: $text = 'Not Modified'; break;
                case 305: $text = 'Use Proxy'; break;
                case 400: $text = 'Bad Request'; break;
                case 401: $text = 'Unauthorized'; break;
                case 402: $text = 'Payment Required'; break;
                case 403: $text = 'Forbidden'; break;
                case 404: $text = 'Not Found'; break;
                case 405: $text = 'Method Not Allowed'; break;
                case 406: $text = 'Not Acceptable'; break;
                case 407: $text = 'Proxy Authentication Required'; break;
                case 408: $text = 'Request Time-out'; break;
                case 409: $text = 'Conflict'; break;
                case 410: $text = 'Gone'; break;
                case 411: $text = 'Length Required'; break;
                case 412: $text = 'Precondition Failed'; break;
                case 413: $text = 'Request Entity Too Large'; break;
                case 414: $text = 'Request-URI Too Large'; break;
                case 415: $text = 'Unsupported Media Type'; break;
                case 500: $text = 'Internal Server Error'; break;
                case 501: $text = 'Not Implemented'; break;
                case 502: $text = 'Bad Gateway'; break;
                case 503: $text = 'Service Unavailable'; break;
                case 504: $text = 'Gateway Time-out'; break;
                case 505: $text = 'HTTP Version not supported'; break;
                default:
                    exit('Unknown http status code "' . htmlentities($code) . '"');
                break;
            }
            $protocol = (isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0');
            header($protocol . ' ' . $code . ' ' . $text);
            $GLOBALS['http_response_code'] = $code;
        } else {
            $code = (isset($GLOBALS['http_response_code']) ? $GLOBALS['http_response_code'] : 200);
        }
        return $code;
    }
}

session_start();
$video_id = get_parameter('v');
validate_video_id($video_id);

try {
    if (isset($_SESSION['video']))
    {
        if ($_SESSION['video'] == $video_id)
        {
            if (isset($_GET['dl']))
            {
                stream_content();
                exit();
            }
        }
        else
        {
            terminate_download();
            start_download();
        }
    }
    else
    {
        start_download();
    }
    get_current_status();
}
catch (Exception $e)
{
    http_response_code(400);
    exit('Caught exception: ' . $e->getMessage() . "\n");

    cleanup();
}
?>