<?php
/**
 *  Скрипт парсинга каталога с письмами на предмет их индексации для интегрирования в БД
 * Параметры: @see if( isset('help') ) below
 *
 * @author-fvn-20151006..
 */

$params = array();

if( $argc>1 )
{
    // @TODO: change it to getopt() ?!?
    for($i=1; $i<$argc; $i++)
    {
        if( strpos($argv[$i],'=')!==false ){
            list($name,$val) = explode('=', $argv[$i]);
        }else{
            $name = $argv[$i];
            $val = '';
        }
        $params[trim($name,'-')] = trim($val);
    }
}

if( isset($params['help']) || isset($params['h']) ){
  echo "
usage {$argv[0]}:
~\$ {$argv[0]} [options]\n
options:
--help                - show this
--threads=int         - how much threads using for parse
--each=int            - how much letters for each thread to parse. 0 - for end
--debug=int           - debug log level {0 - silent, 1-error,2-warning,3-info}
--dir='dir_to_letter' - start dir with mails ('' - must be present!)
--out='file_to_json'  - destination for output json ('' - must be present!). default: mails.json into each --dir
--log='dir_to_log'    - destination for logger ('' - must be present!)
--headers=int         - 2: save only in \$headersJSON headers, 1: save all headers without \$withoutHeaders array (default)
--mysql               - save into `rotor` DB, `tmail`, `tmail_file`, `tmail_address`,`tmail_header` tables too.
--nojson              - not save into json, read 'out' dir for json-preparsed files (with mysql option only!)
--errdir='path'       - if present, this is a dir where will be storing erroring mails as is with your mail name
--farch='name'        - if present, this text will be saved in json field as 'farch' as is.

if any is absent, will be use mailparser_ini.php file! See this file for other not command-line params..\n
  ";
  exit(0);
}

include 'mailparser_ini.php';

// Добираем незаданные параметры запуска из .ini файла:
foreach($defParams as $name=>$val){
    if( empty($params[$name]) ){ $params[$name] = $val; }
}

echo "\nStarting with: ".print_r($params, true);
//exit(0);

// can't use process library: commented.
//$workPids = 0;
//$forks = array();

for($i=0; $i<$params['threads']; $i++)
{
    $params['id'] = $i;     // локальный номер процесса из 'threads' штук.

//    echo "\n thread {$i} - started.";

    /**
     * pcntl_fork() - данная функция разветвляет текущий процесс
     */
    $pid = pcntl_fork();
    if ($pid == -1) {
        /**
         * Не получилось сделать форк процесса, о чем сообщим в консоль
         */
        die('Error fork process' . PHP_EOL);
    } elseif ($pid) {
        /**
         * В эту ветку зайдет только родительский процесс
         */
        echo "\nFork {$i} started. pid={$pid}";
//        die("\nDie parent process {$i}");
    } else {
        /** Типа тело демона тут, пусть ждет. */
//        posix_setsid();
        $cmd = 'php -f index.php -- \''.json_encode($params).'\' &';
        echo "\n Daemon {$i}. cmd={$cmd}";
        system($cmd);
        die("\n System {$i}: executed.");
    }

/*
    $forks[$i] = array('pid'=>null, 'pipes'=>[]);

    $forks[$i]['pid'] = proc_open('exec php -f index.php -- '.json_encode($params)
        , array(
            0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
            1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
            2 => array("pipe", "w")   // array("file", "/tmp/error-output.txt", "a") // stderr is a file to write to
        )
        , $forks[$i]['pipes'] // OUT //
    );
    if (is_resource($forks[$i]['pid'])) { $workPids++; }
}

while( $workPids > 0 )
{
    for($i=0; $i<$params['threads']; $i++)
    {
        if (is_resource($forks[$i]['pid']))
        {
            $forks[$i]['status'] = proc_get_status($forks[$i]['pid']);
            if( !$forks[$i]['status']['running'] )
            {
                foreach($forks[$i]['pipes'] as $pipe){ fclose($pipe); }

                $return_value = proc_close($forks[$i]['pid']);
                echo "\nThread {$i}, pid={$forks[$i]['status']['pid']}: returned $return_value, exitcode={$forks[$i]['status']['exitcode']}";
                $forks[$i]['pid'] = false;
                $workPids--;
            } else {
                // тут надо организовать вывод по статусам процессов и их градусникам..
                echo stream_get_contents($forks[$i]['pipes'][1]);
                echo stream_get_contents($forks[$i]['pipes'][2]);
            }
        }
    }
    usleep(100);
*/
};

while ( ($chpid=pcntl_waitpid(0, $status)) != -1) {
    $status = pcntl_wexitstatus($status);
    echo "Child $chpid completed with $status \n";
}

echo "\n process {$pid} is ended."; exit(0);