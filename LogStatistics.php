<?php
/**
 * Плагин для сбора статистики отдаваемых страниц сервером
 * -------------------------------------------------------
 * Делает запись в таблицу учета дневной статистики запросов и подгружает на страницу js-скрипт,
 * который "свистит" на сервер подтвердая активность страницы в браузере клиента.
 *
 * Настройки:
 * 1. Статический массив $Config[] содержит: режим работы плагина, начальный временной интервал "свистка", общее
 * количество "свистков" на сервер (урл учета статистики - константа здесь же), куда свистеть в очередной раз и два списка
 * а) урлы, к которым НАДО подключать скрипт (пока не используется)
 * б) урлы, к которым НЕ НАДО проводить учет статистики совсем.
 *
 * stat.js -- подключаемый скрипт периодических свистков. На сегодня удваивает время между свистками каждый второй свисток.
 *            т.е.: (3,3,6,6,12,12,24,24,48,48...) и т.д. при начальной установке в 3сек. При текущих значениях в 3сек и 24 свистках
 *            позволяет оценивать время нахождения страницы в браузере от 3сек до 6.8часа...
 *
 * @author fvn-20120321 started .. 20120327 worked.
 * @see plugins/stat.js                                        -- send request every timeout
 * @see modules/stat/controllers/Statistics.php::indexAction() -- update every timeout
 * @see dbs/statistics/Daily.php                               -- model database (insert, update).
 * @todo:
 *   1. Прием "косвенных" запросов - учет статистики "левых" серверов.
 *   2. Вынос всего комплекта на чистое PHP в отдельный сервер статистики с отдельной базой.
 *   3. Админка по управлению:
 *     а) настройками плагина (включение-выключение, изменение параметров и списков);
 *     б) режимами свистков из stat.js (наборы правил переключения)
 *     в) учетными параметрами - создание класса "счетчик" для предоставления косвенного учета по заказам.
 *     г) управление счетчиками - периоды хранения стат данных и т.д.
 */
class LogStatistics extends Zend_Controller_Plugin_Abstract {

	const STAT_SCRIPT = '/application/plugins/stat.js';		// Скрипт сбора статистики и таймер вермени пребывания.
	const STAT_URL = '/stat/statistics/index';				// Урл сервера статистики. Пока наш же контроллер.
	const MAX_PERIOD = 20;		// максимальное количество дозапросов таймера - до 30мин на страницу.
	const TIME_PERIOD = 10000;	// время между дозапросами таймера - 5 сек. Точность подсчета.

	/**
	 * Массив настроечных параметров плагина:
	 *
	 * @var array
	 * @todo: впоследствии можно добавить админку и хранение в БД...
	 */
	static $Config = array(
		'statIsCollect' => true,
		'time_period'	=> self::TIME_PERIOD,
		'max_period'	=> self::MAX_PERIOD,
		'stat_url'		=> self::STAT_URL,
		// набор урлов и параметров, которым инжектируется скрипт активности страниц портала. null - по умолчанию.
		'uri2js' => array(
			'/firms/show/index'=>array('time_period'=>3000, 'max_period'=>200),			// карточка фирмы
			'/goods/show/index'=>null,
			'/goods/show/search'=>null,
		),
		// список образцов ури, которые пропускаем сразу { /module/controller/action }!
		'urinotjs' => array(
			  self::STAT_URL
			, '/stat/index/statmonthly'
			, '/stat/index/mcstatall'
		)
	);

//	public function __construct() { parent::__construct(); }

    /**
     * Создаем стат-запись до начала цикла дистпетчеризации.
     * (чтобы при редиректах не отрабатывалось ложно повторно!)
     *
     * @param Zend_Controller_Request_Abstract $request
     * @return void
     */
    public function dispatchLoopStartup(Zend_Controller_Request_Abstract $request)
    {
    	$params=$request->getParams();
		$uri = '/'.$params['module'].'/'.$params['controller'].'/'.$params['action'];
    	if( in_array($uri, self::$Config['urinotjs']) ) { return; }

    	$urlParsed = parse_url($_SERVER['REQUEST_URI']);
    	$refParsed = ($_SERVER['HTTP_REFERER']? parse_url($_SERVER['HTTP_REFERER']) : '');

    	$toStat = array(
    		  'to_ip'			=> ($_SERVER['SERVER_ADDR']				? $_SERVER['SERVER_ADDR']			: '')
    		, 'scheme'			=> ($_SERVER['SERVER_PROTOCOL']			? $_SERVER['SERVER_PROTOCOL']		: '')
    		, 'port'			=> ($_SERVER['SERVER_PORT']				? $_SERVER['SERVER_PORT']			: 0)
    		, 'host'			=> ($_SERVER['SERVER_NAME']				? $_SERVER['SERVER_NAME']			: '')
    		, 'path'			=> ($urlParsed['path']					? $urlParsed['path']				: '')
    		, 'query'			=> ($urlParsed['query']					? $urlParsed['query']				: '')
    		, 'module'			=> ($params['module']					? $params['module']					: '')
    		, 'controller'		=> ($params['controller']				? $params['controller']				: '')
    		, 'action'			=> ($params['action']					? $params['action']					: '')
    		//, 'url'			=> Zend_Json::encode($urlParsed)
    		, 'params'			=> ($params								? Zend_Json::encode($params)		: '')
    		, 'cookie'			=> Zend_Json::encode($_COOKIE)
    		, 'remote_addr'		=> ($_SERVER['REMOTE_ADDR']				? $_SERVER['REMOTE_ADDR']			: '')
    		, 'forwarded_for'	=> ($_SERVER['HTTP_X_FORWARDED_FOR']	? $_SERVER['HTTP_X_FORWARDED_FOR']	: '')
    		, 'user_agent'		=> ($_SERVER['HTTP_USER_AGENT']			? $_SERVER['HTTP_USER_AGENT']		: '')
    		, 'ref_scheme'		=> ($refParsed['scheme']				? $refParsed['scheme']				: '')
    		, 'ref_port'		=> ($refParsed['port']					? $refParsed['port']				: 0)
    		, 'ref_host'		=> ($refParsed['host']					? $refParsed['host']				: '')
    		, 'ref_path'		=> ($refParsed['path']					? $refParsed['path']				: '')
    		, 'ref_user'		=> ($refParsed['user']					? $refParsed['user']				: '')
    		, 'ref_pass'		=> ($refParsed['pass']					? $refParsed['pass']				: '')
    		, 'ref_query'		=> ($refParsed['query']					? $refParsed['query']				: '')
    		, 'ref_fragment'	=> ($refParsed['fragment']				? $refParsed['fragment']			: '')
    		//, 'referer'		=> Zend_Json::encode($refParsed)
    		, 'user_data'		=> ''
    		, 'time1'			=> 0
    		, 'time'			=> 0
    		, 'periods'			=> 0
    		, 'max_periods'		=> 0
    		, 'count'			=> 0
    		, 'created_at'		=> date('Y-m-d H:i:s')
    	);
    	$toStat['hash'] = MD5(implode(':',$toStat).':'.date('Y-m-d H:i:s'));
//die(var_dump($_SERVER, $_COOKIE, $urlParsed, $refParsed, $toStat, $request));

		$statModel = new Daily();
    	if( self::$Config['statIsCollect'] && $this->isAdvisedURI($uri) && $statModel->insert($toStat) )
    	{
    		$id = $statModel->getAdapter()->lastInsertId();
    		$this->addScript(self::STAT_SCRIPT, array(
    			  'hash'		=> ($idHash = $id.'-'.$toStat['hash'])
    			, 'period'		=> self::$Config['time_period']
    			, 'maxPeriod'	=> self::$Config['max_period']
    			, 'statUrl'		=> self::$Config['stat_url']
    		));
    	} else $idHash = 'withoutStats'; // die('Server mysql not worked are stabled with stats... mailng to support@mediam.ru please.');
    	Zend_Registry::set('statIdHash', $idHash);
	}

    /**
     * Проверяет надо ли инжектировать скрипт в страницу по настроечному массиву (пока)
     * @todo: по завершению SSO - заменить носатроечный массив на выборку из SSO
     *
     * @params string $uri == parse_url()['path']
     * @return bool
     *
     * @author fvn-20120321
     * @see self::dispatchLoopStartup()
     */
    public function isAdvisedURI($uri) {
    	return true; // пока ведем учет посещаемости всех урлов...
/*
    	if( array_key_exists($uri, self::$Config['uri2js'])) return true;
    	return false;
*/    }

    /**
     * Инжектирует скрипт статистики в страницу.
     *
     * @param string $path_name == полный путь от DOCUMENT_ROOT до скрипта
     * @param array  $params == массив параметров стартовой функции скрипта stat2mediam($params)
     * @return void
     *
     * @author fvn-20120321
     * @see self::dispatchLoopStartup()
     */
	public function addScript($path_name = self::STAT_SCRIPT, $params = array() ) {
		$view = Zend_Controller_Action_HelperBroker::getStaticHelper('viewRenderer')->view;
		$view->headScript()->appendFile($path_name);
		$view->headScript()->appendScript('stat2mediam(' . Zend_Json::encode($params) . ');');
	}
}