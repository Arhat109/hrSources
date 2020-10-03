<?php
/**
 * Класс разборщика прайсовых файлов клиентов портала.
 * ---------------------------------------------------
 * Порядок начала работы описан в базовом классе, дополнительно:
 * 1. Сознательно (как пример) в данном классе ОБЪЕДИНЕНО 2 вида парсеров, а именно:
 * 		а) - разбор прайсовых файлов через библиотеку PHPExcel;
 * 		б) - переразбор предварительно сохраненных в БД строк файлов.
 * 	Определение какой вариант вызван сделано в методе ->_beforeParse(), который вызывает отдельные
 * процедуры настройки параметров парсера и провод предварительную подготовку данных, в том числе и 
 * для второго прохода.
 * 
 * 2. Парсер из файла реализован 2-х проходным: второй проход - обработка изображений, которая выполнена в
 * 		конце разбора в методе ->_afterParse().
 * 
 * 3. Реализовано перекрытие базовых методов и формирование стратегий разбора.
 * -------------------------------------------------------------------------------------------------
 * 2011.12.29: Добавлен переразбор оригинальной строки по новому набору товарных рубрик фирмы.
 * 2012-01-12: разделено на базовый класс парсеров и этот специальный парсер прайсовых файлов...
 * 2012.02.14: Добавлен разбор нескольких картинок к строке и их корректное сохранение при переразборах.
 * 2012.10.10: Добавлена колонка с урлами до картинок и их вытаскивание из интернет, ещё на первом проходе.
 * 2012.12.04: Выделены отдельные операции БМУ, в связи с чем
 *     практически объединеные оба типа парсеров в один.
 *     , добавлены параметры описания структуры прайса `goods`.`pricefiles_params`
 * 2012.12.18: Добавлен режим повторного обновления из файла:
 *     , добавлены режимы обновления $config['updMode'] побитно:
 *         -- бит 1 : новые строки пропускать/перепарсивать
 *         -- бит 2 : старые строки оставлять / удалять
 * 
 * @author fvn-20111218..
 * @see /default/models/Parser.php -- базовый класс парсеров.
 * , @see /cron/parserpf.php                                    -- асинхронная запускалка парсера.
 * , @see /cabinet/controllers/AjaxController->parserpfAction() -- инициализация парсера как асинхронного процесса.
 * , @see /public/scripts/cabinet/parserpf.js                   -- диалог браузера по запуску асинхронного парсера.
 */
class GoodsParser extends Parser {

	const STATE_SAVED = 0;
	const STATE_LOCKED = 1;
	const STATE_PARSED = 2;

	const START_COL = 0;
	const MAX_ALLOWED_IMAGES = 5;
	const COL_MAXCOLS = 7;
	const MAX_IMAGE_SIZE = 2097152;	// < 2mb!
/**
 * fvn-20121204: Константы заменены на свойство класса (массив) для работы с разными типами файлов:
 * Колонки ($this->_cols) могут теперь принимать значения: {int|string|false|array}:
 *   int    -- номер колонки в файле ("А"==1)
 * , string -- подставляемая константа (строка) для всех значений поля. Только для наименований!
 * , false  -- нет колонки в файле!
 * , array  -- список колонок для конкатенации значений из нескольких (только наименование).
 * введены _skip_rows - количество пропускаемых строк заголовка.
 * и _images_in       - признак искать ли интегрированные в файл картинки к товарам. Не обрабатывается ещё!
 * 
 * Всё это храниться к каждому прайсу в дополнительной табличке типов файлов `pricefiles_params` в поле `format_json`
 * 
 * Добавлен конфигурационный параметр $config['upds'] = array() -- какие колонки откуда брать при повторной заливке файла
 * формат: 'name'=>{'1'|'2'|'3'} - колонка берется из новой заливки | или из старой.
 * fvn-20121218:
 * Добавлен параметр $config['update'] -- равен id обновляемого прайса ($this->_pfid)
 * Добавлен параметр $config['updMode'] -- 0..3 -- режим обновления что делать с новыми строками и со старыми.
 */
	protected $_cols = array('num'=> 0, 'name'=>1, 'artukul'=>2, 'price'=>3, 'mesunit'=>4, 'barcode'=>5, 'image'=>6);
	protected $_skip_rows = 0;
	protected $_images_in  = true;

	// параметры запуска парсера:
	protected $_pfid = 0;
	protected $_uid = 0;
	protected $_fid = 0;
	protected $_state = 'start';	// по умолчанию ищем в прайсовых файлах 'xls'
	protected $_onlyMy = true;		// режим поиска по ассортиментным строкам только этой или любых фирм.

	// текущие данные разбора:
	protected $pfModel       = null;			// доступ к классу данных о прайсовых файлах...
	protected $pfrModel      = null;			// доступ к классу таблицы прайсовых строк.
	protected $pfgModel      = null;			// доступ к классу аналогов товаров в строках клиента
	protected $imgModel      = null;			// доступ к классу изображений товарных строк.
	protected $pfrImageModel = null;			// доступ к классу связи прайсовых строк с картинками.
	protected $priceModel    = null;			// доступ к классу прайсовых строк (общему) для поиска аналогов.

	protected $_startCol   = self::START_COL;
	protected $_nCols      = 0;
	protected $_sheet      = null;		// лист файла ...
	protected $_sqlData    = null;		// ... или выборка из `goods`.`pricefilesRows`::state
	protected $_imgs       = null;		// собственно список картинок из файла разбора.
	protected $_imgsCounts = null;		// added fvn-20121010: массив количества картинок добавленных к строкам.
	protected $_cntImages  = 0;
	protected $_imageDir   = '';
	protected $_ids        = null;		// массив номеров разобранных строк (id) и title для последующей привязки картинок. 
	protected $_maxAllowedImages = self::MAX_ALLOWED_IMAGES;	// ВРЕМЕННО! Надо заводить в правила биллинга и пакетов!

	/**
	 * Опции конфигуратора:
	 * 
	 * прайс,юзер,фирма,тип,поМоим,картинок:
	 * 'pfid','uid','myCompanyId','state','onlyMy','max_images',
	 * вывод,пустая-ошибка,начать,всего,БМУ,адаптер:
	 * 'debug','is_strong','startRow','nRows','commands','db'
	 * 'update'=>array('name'=>{'isNew'|'isOld'}) -- режим update: какую колонку откуда брать.
	 * 'notFile' -- если задан, то не читаем описание формата файла - нет файла и структуры
	 * , (!но есть умолчания!) @see /goods/AjaxController->addrowAction().
	 * @param array $config
	 */
	public function __construct( array $config = array() ) {
//$config['debug']=Parser::DEBUG_INFO;
		$this->pfModel		= new Pricefiles($config);
		$this->pfrModel		= new PricefilesRows($config);
		$this->pfgModel		= new PricefilesGoods($config);
		$this->imgModel		= new Images($config);
		$this->pfrImageModel= new PricefilesImages($config);
		$this->priceModel   = new PricesTable($config);

		$this->_pfid  = (isset($config['pfid']) ? $config['pfid'] : null); unset($config['pfid']);
		$this->_uid   = (isset($config['id']) ? $config['id'] : null); unset($config['id']);
		$this->_fid   = (isset($config['myCompanyId']) ? $config['myCompanyId'] : null); unset($config['myCompanyId']);
		$this->_state = (isset($config['state']) ? $config['state'] : 'start'); unset($config['state']);
		$this->_onlyMy= (isset($config['onlyMy']) ? $config['onlyMy'] : true); unset($config['onlyMy']);
		$this->_maxAllowedImages = (isset($config['max_images'])? $config['max_images'] : self::MAX_ALLOWED_IMAGES); unset($config['max_images']);
		// fvn-20121206: update - 0 или == ['pfid'] обновление прайса. upds - что обновляем:
		$this->_update  = (isset($config['update'])? $config['update']: null); unset($config['update']);
		$this->_updMode = (isset($config['updMode'])? $config['updMode']: null); unset($config['updMode']);
		$this->_upds    = (isset($config['upds'])? $config['upds']: null); unset($config['upds']);

		parent::__construct($config);

		if( isset($config['notFile']) ) { return; }

		// fvn-20121204: Временно - нет модели: читаем описание прайса и меняем с "по умолчанию" если нашли:
		$params = $this->_db->fetchOne(
			$this->_db->select()
				->from(array('pf'=>Pricefiles::TABLE), array('params'=>'pfp.format_json'), Pricefiles::SCHEMA)
				->join(array('pfp'=>'pricefiles_params'),'pfp.id = pf.params_id','', 'goods')
				->where('pf.id = ?', $this->_pfid, Zend_Db::PARAM_INT)
		);
		if( $params ) {
			$params = json_decode($params);
			$this->_cols = (array)$params->cols;
			$this->_skip_rows = $params->skip_rows;
			$this->_images_in = $params->images_in;
			$this->outMessage(DEBUG_INFO,
				"\n Getted format: cols=>".print_r($this->_cols, true).", skips={$this->_skip_rows}, images_in={$this->_images_in}"
			);
		} else {
			$this->setError(Parser::NOT_PFID, '__construct', 0, 'Не задана структура файла: непонятно где что искать по строкам... Прервано.');
		}
	}

	/**
	 * Формирует ошибку возврата по заданному коду.
	 * 
	 * @param int		$errCode	-- номер ошибки
	 * @param string	$func		-- имя метода, вызвавшего ошибку.
	 * @param int		$row		-- текущая строка генерации ошибки.
	 * @return $errCode
	 */
	public function setError( $errCode, $func, $row = 0, $message = '' ) {

		parent::setError($errCode, $func, $row, $message);
		// + устанавливаем ошибку файла!
		$this->pfModel->setState($this->_pfid, $this->_uid, $errCode, $this->_error['message']);
		return $errCode;
	}

	/**
	 * Возвращает запрос для подбора аналогов с ограничением по рубрикам фирмы
	 * 
	 * @param string $namePart
	 * @return Zend_Db_Select
	 * 
	 * @deprecated ! by fvn-20121011 -- только мешает работе! Надо искать вид товара в любом допустимом разделе, говорил сразу... нет же! ;)
	 * ВНИМАТЕЛЬНО! отключение cwg_firms сделано в следующих местах, кроме этого:
	 * 1. @see /goods/models/Goods.php->insertOrUpdate()   -- селект похожих товаров на новый
	 * 2. @see /dbs/mediam/PricefilesRows->getParsedRows() -- замена имен полей на поля из kernel.m_activity можно не менять обратно.
	 * 3. @see /dbs/goods/WareGroups->getSelectOptions()   -- добавлен джойн с m_activity и поля выбираются из него иначе рухнет нумерация разделов.  
	 */
	public function getSelect2($namePart)
	{
		return false; /*parent::getSelect($namePart)
			->join('cwg_firms as cwg',
				'(cwg.m_activity_id = ma2.id) AND (cwg.m_company_id = '.$this->_fid.
				') AND (cwg.status & 1)','','goods')
		;*/
	}
	/**
	 * Перекрыт для поиска нескольких слов в видах товаров.
	 * Формирует запрос к видам товаров по словам из $this->_words, через выборку
	 * только имен существительных сначала строки вида товара.
	 * 
	 * @return RowsetArray {Good_Ident, Name_ofGood, Gr_Ident, SGr_Ident, ma_id, ma_name}
	 * @author fvn-20121031
	 * @see Parser::findGoodName() -- использует это перекрытие для поиска видов товаров:
	 * 
	 * @deprecated by fvn-20121122 -- медленно и плохо:
	 * , или слишком много находит или надо делать union из повторных поисков и так долго (до 8сек на строку!)
	 * , для дальнейшего развития - надо думать. Пока сделан 2 вариант парсера: @see goods/models/GoodsParser2.php
	 */
/*	public function getSelect()
	{
return parent::getSelect();

		$words = ''; $wnum = 0;
		foreach($this->_words AS $w) if( !empty($w) )
		{
			$usql .= ($usql? ' UNION ' : '').'SELECT "'.addslashes($w).'"'.($usql? '' : ' AS word ').', '.$wnum++.($usql? '' : ' AS wnum ');
		}
		return $this->_db->select()
			/* вариант поиска через словарь Зализняка (плохо):
			->from('z_dict AS z','', 'goods')
			->join('z_dict_eav AS ze'
				, 'ze.word_id = z.id AND ze.attr_id = '.ZDict::PARAM_GRAMMA.' AND ze.val_id ='.ZDict::GRAMMA_NOUN
				, ''
				, 'goods'
			)
			->join(array('a' => Goods::TABLE)
				, 'LOCATE(z.word, a.Name_of_good) = 1'
					.' AND ((@l:=CHAR_LENGTH(z.word))=CHAR_LENGTH(a.Name_of_good) OR LOCATE(" ", a.Name_of_good, (@l+1))=(@l+1))'
				, array('Good_Ident', 'Name_of_Good', 'Gr_Ident', 'Sgr_Ident')
				, Goods::SCHEMA
			)*/
/*			->from(array('a'=> Goods::TABLE)
				, array('Good_Ident', 'Name_of_Good', 'Gr_Ident', 'SGr_Ident', 'words', 'COUNT(w.word) AS cnt'
  					, 'SUM(w.word = SUBSTRING_INDEX(a.Name_of_good, " ", 1)) AS at1'
				)
				, Goods::SCHEMA
			)
			->join(array('w'=>new Zend_Db_Expr('('.$usql.')'))
				, new Zend_Db_Expr(
					'(w.word = SUBSTRING_INDEX(a.Name_of_good, " ", 1))'		// ищем каждое слово целиком и только с начала вида товара!
					.' OR ((@pos:=(LOCATE(w.word, a.Name_of_good))) > 0'	// или целое слово внутри строки
        			.' AND IF(@pos>1, SUBSTRING(a.Name_of_good, @pos-1, 1) = " ", true)'
        			.' AND IF((@pos:=CHAR_LENGTH(w.word)+@pos)<CHAR_LENGTH(a.Name_of_good), SUBSTRING(a.Name_of_good, @pos, 1) = " ", true))'
				)
				, 'COUNT(w.word) AS cnt'
				//,''	//пока так. выбираем только те виды товаров, которые сджойнились со словами сначала вида товара!
			)
			->join(array('ma2'=>Activities::getSelect('ma', array('id','name','grid','sgrid'))),
				'ma2.grid=a.Gr_Ident AND ma2.sgrid = a.SGr_Ident', array('ma_name'=>'ma2.name','ma_id'=>'ma2.id'))
			//->where('z.word IN ("'.$zwords.'")')
			//->where('a.Name_of_good REGEXP ".*(?).*"', $words)
			//->where(new Zend_Db_Expr('LOCATE(w.word, a.Name_of_good)>0'))
			->group('a.Good_Ident')
			//->having('cnt = a.words')
			//->order( array('a.Name_of_good ASC','a.Gr_Ident ASC','a.SGr_Ident ASC') )
			//->order('w.wnum ASC')
			->order(array('at1 DESC','cnt DESC'))
			->limit(10)
		;
	} */
	/**
	 * БМУ. Поиск видов товаров через таблицу всех строк фирмы.
	 * Использует дополнительную настройку
	 * $this->_onlyMy - по всем строкам или только текущей фирмы.
	 * 
	 * @param int	$row -- номер текущей строки
	 * @param array $data -- результат разбора текущей строки. 'gooddesc' - где ищем
	 * @return self::{OK|SKIP|STOP|array error} -- режим продолжения 
	 */
	public function findArchGoods($row, &$data) {

		$this->getFirstWord($data['gooddesc']); // только массив $this->_words!

		$namePart = $data['gooddesc'];
		if( !$namePart ) {
			return ($this->_is_strong ?
				$this->setError(Parser::BAD_GOODNAME, 'findArchGood', $row)
				: Parser::SKIP
			);
		}
		$select = $this->_db->select()
			->from($this->priceModel->getFrom('ar'), array('Good_Ident' => 'ar.goods_id'))
			->join(array('cg'=>Goods::TABLE),'cg.Good_Ident = ar.goods_id',
				array('Name_of_good'=>'cg.Name_of_good','Gr_Ident'=>'cg.Gr_Ident','SGr_Ident'=>'cg.SGr_Ident'), Goods::SCHEMA)
			->order('IFNULL(ar.updated_at, ar.created_at) DESC')
			->limit(1)
		;
		if( $this->_onlyMy ) {
			$select->where('ar.m_company_id = ?', $this->_fid, Zend_Db::PARAM_INT);
		}
		if( !empty($data['article']) || !empty($data['barcode']) ) {
			// первый вариант поиска таких же строк по артикулу или бар-коду:
			if( !empty($data['article']) ) { $select->where('ar.article = ?', $data['article'] ); }
			if( !empty($data['barcode']) ) { $select->where('ar.barcode = ?', $data['barcode'] ); }
			$this->parseGoods( $this->searchGoods($namePart, $select) );
		}
		if( empty($this->_goods) ) {
			// второй вариант поиска таких же строк: ищем по всем строкам фирмы точное совпадение:
			$select->reset(Zend_Db_Select::WHERE);
			$select->where('ar.name = ?', $namePart );
			$this->parseGoods( $this->searchGoods($namePart, $select) );
		}
		// fvn-20121011: если нашли ровно один товар в архиве - пропускаем следующую команду - общий поиск.
		return (count($this->_goods) == 1? Parser::SKIP1 : Parser::OK);
	}

	/**
	 * БМУ. Читаем картинки из интернет (только по http!)
	 * , если задан урл в соответствующей колонке прайс-листа:
	 * !Внимательно! Строка должна уже быть сохранена и добавлена в список для картинок @see $this->addRowIds()
	 * @see rev.12313: Добавлен параметр - признак, что нет данных в массиве
	 * , только для прямого вызова метода! @see $this->getUpdateRow()
	 * 
	 * 
	 * @param int     $row      -- текущий номер строки разбора
	 * @param array   $data     -- текущая строка разбора создается в getRowXLS()
	 * @param bool    $isInData -- НЕ ДЛЯ БМУ! Признак, что данные номера строки и описание брать из массива - прямой вызов!
	 * @return self::{OK|SKIP|STOP|array error} -- режим продолжения 
	 * 
	 * @author fvn-20121010: добавлено. fvn-20130404: добавлен параметр для прямого вызова.
	 * @see $this->getUpdateRow()
	 */
	public function getImageByUrl($row, &$data, $isINData = false)
	{
		if( $data['imageUrl'] != '') {
			$url = parse_url($data['imageUrl']);
			$url['scheme'] = 'http';					// все равно было, не было рабтаем только по этому протоколу!
			if( !isset($url['host']) || empty($url['host']) ) return Parser::OK;

			$urlString = "{$url['scheme']}://{$url['host']}{$url['path']}".($url['query']? '?'.$url['query']: '');
			$imData = getimagesize( $urlString );
			$image = file_get_contents( $urlString );

			if( $imData === false || $image === false ) {
				$this->outMessage(Parser::DEBUG_WARNING, "\nError in row=$row, can't get image from url={$data['imageUrl']}");
				return Parser::OK;
			}
			// fvn-20121203: проверяем создан ли каталог для картинок этого файла:
			if( empty($this->_imageDir) ) $this->createImageDir();
			// считает, проверяет на кол-во (в этой загрузке!) и добавляет картинку:
			$this->saveImage(
					  imagecreatefromstring($image)
					, $imData
					, $row
					, ($isINData? $data['id'] : null)
					, ($isINData? $data['gooddesc'] : '')
			);
		} else $this->outMessage(Parser::DEBUG_INFO, "... absents image in row.");
		return Parser::OK;
	}
	/**
	 * БМУ. Читаем картинки из свежезагруженного файла (из форм)
	 * !Внимательно! Строка должна уже быть сохранена и добавлена в список для картинок
	 * @see $this->addRowIds()
	 * 
	 * @param int     $row  -- текущий номер строки разбора
	 * @param array   $data -- текущая строка разбора создается в getRowXLS()
	 * @return self::{OK|SKIP|STOP|array error} -- режим продолжения 
	 * 
	 * @author fvn-20130111: добавлено.
	 */
	public function getImageUploaded($row, &$data)
	{
		$image = file_get_contents($data['imageUrl']['tmp_name']);
		$imData = getimagesize( $data['imageUrl']['tmp_name'] );

		if( $imData === false || $image === false ) {
			$this->outMessage(Parser::DEBUG_WARNING, "\nError in row=$row, can't get image from url={$data['imageUrl']}");
			return Parser::OK;
		}
		// fvn-20121203: проверяем создан ли каталог для картинок этого файла:
		if( empty($this->_imageDir) ) $this->createImageDir();
		// считает, проверяет на кол-во (в этой загрузке!) и добавляет картинку:
		$this->saveImage(
				  imagecreatefromstring($image)
				, $imData
				, $row
		);
		$data['imageUrl'] = $data['imageUrl']['name'];

		return Parser::OK;
	}
	/**
	 * БМУ. Добавление номера прайсовой строки в список номеров строк
	 * для обработки картинок вторым проходом в $this->_afterParse()
	 * 
	 * @param int     $row  -- текущий номер строки разбора
	 * @param array   $data -- текущая строка разбора создается в getRowXLS()
	 * @return self::{OK|SKIP|STOP|array error} -- режим продолжения 
	 * 
	 * @author fvn-20121210 -- завершен вынос в отдельные функции каждой операции БМУ.
	 */
	public function addRowIds( $row, &$data)
	{
		//готовим список для второго прохода по картинкам:
		$this->_ids[$row]['id']    = $data['id'];
		$this->_ids[$row]['title'] = $data['gooddesc'];
		return Parser::OK;
	}
	
	/**
	 * БМУ. Сохранение прайсовой строки и найденных товаров в БД.
	 * 
	 * @param int     $row  -- текущий номер строки разбора
	 * @param array   $data -- текущая строка разбора создается в getRowXLS()
	 * @return self::{OK|SKIP|STOP|array error} -- режим продолжения 
	 * 
	 * @author fvn-20121210 -- завершен вынос в отдельные функции каждой операции БМУ.
	 * @deprecated , оставлена для совместимости.
	 */
	protected function savePriceRow($row, &$data) {

		$res = $this->_db->beginTransaction();

		// В случае ошибок, все команды БМУ самостоятельно делают $this->rollback()!
		$res = $this->insertRow       ($row, $data);	if( $res != Parser::OK ) return $res;
		$res = $this->insertGoods     ($row, $data);	if( $res != Parser::OK ) return $res;
		$res = $this->addRowIds       ($row, $data);	if( $res != Parser::OK ) return $res;
		$res = $this->saveBarProgress ($row, $data);	if( $res != Parser::OK ) return $res;

		$this->_db->commit();
		return Parser::OK;
	}

	/**
	 * БМУ. Режим обновления строк из файла. По артикулу!
	 * Ищет оригинал в предыдущем разборе обновляемого прайса и его товар(ы).
	 * Если нашел - заменяем чего надо и обновляем строку ч/з: insertRow().
	 * , иначе: $this->_updMode {1,3} - пишем её как новую в нераспознанные строки ч/з: savePriceRow().
	 * , $this->_updMode {0,2} - пропускаем новую строку.
	 * 
	 * @param int     $row  -- текущий номер строки разбора
	 * @param array   $data -- текущая строка разбора создается в getRowXLS()
	 * @return self::{OK|SKIP|STOP|array error} -- режим продолжения 
	 * 
	 * @author fvn-20121207
	 */
	public function getUpdateRow($row, &$data)
	{
		// ищем строку в разобранном ранее прайсе:
		$newRow = $data;
		$select = $this->pfrModel->select()
			->from(array('pfr'=>PricefilesRows::TABLE), '*', PricefilesRows::SCHEMA)
			->where('pfid = ?', (int)$this->_pfid, Zend_Db::PARAM_INT)
		;
		// fvn-20121218: формируем режим поиска тойже строки по $this->_upds[]
		// @see /cabinet/views/scripts/prices/loadprice.phtml -- upds[] == {0-not use, 1- updating, 2 - searching}
		$this->_findOld = ''; $errMessage = '';
		if( (int)$this->_upds['name']    == 2) { $select->where('gooddesc = ?', (string)$data['gooddesc']); $errMessage .= 'наименование'; }
		if( (int)$this->_upds['artikul'] == 2) { $select->where('article  = ?', (string)$data['article']);  $errMessage .= ' артикул'; }
		if( (int)$this->_upds['barcode'] == 2) { $select->where('barcode  = ?', (string)$data['barcode']);  $errMessage .= ' штрихкод'; }

		$this->outMessage(Parser::DEBUG_INFO, "\n getUpdateRow: search similar: " . (string)$select );
		$oldRow = $this->pfrModel->fetchAll($select, array(), Zend_Db::FETCH_ASSOC);
		if( $oldRow === false || count($oldRow) == 0 ) {
			// не нашли, новая строка: @see /cabinet/views/scripts/prices/loadprice.phtml -- updMode: {0,2} skip new, {1,3} add new rows!
			$this->outMessage(Parser::DEBUG_INFO, '... not founded: '.($this->_updMode % 2? 'added':'skipped').'.');
			return ($this->_updMode % 2? Parser::OK : Parser::SKIP);
		}
		$this->outMessage(Parser::DEBUG_INFO, '... founded: '.count($oldRow));

		if( count($oldRow) > 1 ) { return $this->setError(Parser::UPDATE_MANY_ROWS,'getUpdateRow', $row, $errMessage.' - д.б. уникальным!'); }
		// найдена и ровно одна строка:
		$data = $oldRow[0]->toArray();
		$res = Parser::GO_YES;
		//Обновляем в старой строке, чего сказано (upds[]=1!): @see /cabinet/views.scripts/prices/loadprice.phtml
		$this->outMessage(Parser::DEBUG_INFO, "\n updating for ".print_r($this->_upds, true)."\n, oldRow was: ".print_r($data, true));
		foreach( $this->_upds as $key => $val ) if( (int)$val == 1 )
		{
			switch($key) {
			case 'name': // новая текстовка товара - переразбор товарных позиций!
				$data['state'] = PricefilesRows::STATE_FAILED;
				$field = 'gooddesc';
				$res = Parser::GO_NOT;
				break;
			case 'price':
				$field = 'goodcost';
				break;
			case 'mesunit':
				$field = 'goodmunit';
				break;
			case 'image':
				$field = 'imageUrl';
				$this->getImageByUrl($row, &$data, true);	// догружаем новую картинку сразу!
				break;
			case 'barcode':
				$field = 'barcode';
				break;
			case 'artikul':
				$field = 'article';
				break;
			default:
				return $this->setError(Parser::UPDATE_NEW_FIELD, 'getUpdateRow', $row);
			}
			$data[$field] = $newRow[$field];	// поля гарантированно называются одинаково!
		}
		return $res;
	}
	/**
	 * БМУ. Функция выборки одной товарной строки файла XLS
	 * 
	 * @param int     $row  -- текущий номер строки разбора
	 * @param array   $data -- текущая строка разбора создается в getRowXLS()
	 * @return self::{OK|SKIP|STOP|array error} -- режим продолжения 
	 * 
	 * @author fvn-20121010: добавлена колонка урлов картинок и работа с ней.
	 * , fvn20121207: замена констант на номера колонок из описания файла, контроль заданности колонок.
	 */
	public function getRowXLS($row, &$data)
	{
		$res = Parser::SKIP;
		$rawData = array();
		for($col = $this->_startCol; $col <= $this->_nCols; $col++) {
			$rawData[$col+1] = $this->_sheet->getCellByColumnAndRow($col, $row)->getValue(); //."\n";
			if( $col == $this->_cols['name'] && $rawData[$col] ) { $res = Parser::OK; }
			$this->outMessage(Parser::DEBUG_INFO, 'row='.$row.', col='.$col.' value = '.$rawData[$col]);
		}
		if( $this->_skip_rows > 0 ) {
			$this->_skip_rows--;
			$this->outMessage(Parser::DEBUG_INFO, "\n..skipped row={$row}.");
			return Parser::SKIP;
		}
		$data = array(
			  'pfid'       => $this->_pfid
			, 'orgprow'    => serialize($rawData)
			, 'goodname'   => ''
			, 'goodcost'   => (float) str_replace(array(' ', ','), array('', '.'), trim($rawData[$this->_cols['price']]))
			, 'goodmunit'  => trim( (int)$this->_cols['mesunit'] > 0 ? $rawData[$this->_cols['mesunit']] : $this->_cols['mesuinmt'])
			, 'article'    => ( (int)$this->_cols['artikul'] > 0? trim($rawData[$this->_cols['artikul']]) : null)
			, 'barcode'    => null //библиотека НЕ читает 13-значные числа! Отдает урезанное число! trim($rawData[$this->_cols['barcode']])
			, 'imageUrl'   => ( (int)$this->_cols['image'] > 0? trim($rawData[$this->_cols['image']]) : '')
			, 'created_by' => $this->_uid
		);
		$data['gooddesc'] = '';
		$names = explode(',', $this->_cols['name']);
		foreach( $names as $num) {
			$data['gooddesc'] .= ($data['gooddesc']? ' ' : '') . trim(str_replace('  ', ' ', $rawData[$num]));
		}
		if( $data['article'] == '' ) $data['article'] = null;
		//if( $data['barcode']  == '' ) $data['barcode'] = null;
		
		$this->outMessage(Parser::DEBUG_INFO, "\t founded: pfid=".$data['pfid'].' orgprow='.$data['orgprow'].
			' goodname='.$data['goodname'].' goodcost='.$data['goodcost'].
			' goodmunit='.$data['goodmunit'].'article='.$data['article'].
			' barcode='.$data['barcode'].' created_by='.$data['created_by'].PHP_EOL
		);
		return $res; //Parser::OK;
	}

	/**
	 * Подготовка данных для разбора файлов формата XLS
	 * 
	 * @param pfRowset $list -- описатель файла.
	 * return true|error
	 * 
	 * fvn20121204: добавлен вариант разбора 'update' -- разбор нового файла для ранее разобранных строк (pfid)
	 * -- дополнительно добавлены данные что откуда брать - из нового файла или из старого разбора.
	 * @see self::__construct()
	 */
	public function initModeXLS( $xls ) {

		$priceFile = SITE_DOCUMENT_ROOT.'/'.Pricefiles::UPLOAD . $xls->fpath . $xls->id .'.'. $xls->ext;

		if ($xls->state == self::STATE_LOCKED)	return $this->setError(Parser::NOT_STATE, 'parser');
		if (!file_exists($priceFile))			return $this->setError(Parser::NOT_EXIST, 'parser');
		$this->outMessage(Parser::DEBUG_INFO, $priceFile . print_r($xls, true));

		$xlsReader = PHPExcel_IOFactory::load($priceFile);
		PHPExcel_Settings::setCacheStorageMethod( PHPExcel_CachedObjectStorageFactory::cache_to_memcache );
		$xlsReader->setActiveSheetIndex(0);

		$this->_sheet = $xlsReader->getActiveSheet();
		$this->_startRow = 1;
		$this->_nRows = $this->_sheet->getHighestRow()+1;
		$this->_startCol = 0;
		$this->_nCols = $this->_sheet->getHighestColumn(); // возвращает БУКВУ!!!
		$len = strlen($this->_nCols);
		$this->outMessage(Parser::DEBUG_INFO, "\n--nCols={$this->_nCols}, len = {$len}\n");

		if( $len > 1 ) {
			// есть "лишние" колонки и далее Z! отрезаем, вирус?!?
			$this->_nCols = self::COL_MAXCOLS;
		} else {
			$this->_nCols = (int)(ord($this->_nCols) - ord('A') + 1);
		}
		$this->_imgsCounts = array();

		if( !empty($this->_update) && (int)$this->_update == (int)$this->_pfid ) {
			// fvn-20121207: режим обновления! Меняем БМУ:
			// Если изменения в названии - вызывает поиск соответствия (Parser::OK == 2 команда!).
			$this->_commands = array(
				  0 => array('name'=>'getRowXLS')			// читаем строку из файла в массив
// getUpdateRow() - учитывает список обновляемых колонок, и если надо догружает картинку!
// возвращает: OK - новая, YES - обновленная, NOT - изменено наименование - перепарсинг с обновлением строки вместо вставки новой!
				, 1 => array('name'=>'getUpdateRow', 'GO_YES'=>20, 'GO_NOT'=>30)
// новая строка - вставка:
//				, 2 => array('name'=>'getWords')			// разбираем строку на слова через SQL контекстный парсер.
				, 3 => array('name'=>'findGoodName')		// ищем вид товара в строке
				, 4 => array('name'=>'beginTransaction')
				, 5 => array('name'=>'deleteGoods')			// удаляем результат предыдущего разбора.
				, 6 => array('name'=>'insertGoods')			// добавляем найденный товары
				, 7 => array('name'=>'insertRow')			// Нашли: insert! только саму строку в БД по новым значениям. state=-1
				, 8 => array('name'=>'addRowIds')			// добавляем строку для поиска картинок 2-й частью парсера.
				, 9 => array('name'=>'saveBarProgress')		// изменяем статус разбора
				,10 => array('name'=>'commit')
				,11 => array('name'=>'goNextString')
// обновление без перепарсинга:
				,20 => array('name'=>'beginTransaction')
				,21 => array('name'=>'updateRow')			// Нашли: update! только саму строку в БД по новым значениям.
				,22 => array('name'=>'saveBarProgress')		// изменяем статус разбора
				,23 => array('name'=>'commit')
				,24 => array('name'=>'goNextString')
// обновление наименований - перепарсинг с обновлением:
//				,30 => array('name'=>'getWords')			// разбираем строку на слова через SQL контекстный парсер.
				,31 => array('name'=>'findGoodName')		// ищем вид товара в строке
				,32 => array('name'=>'beginTransaction')
				,33 => array('name'=>'deleteGoods')			// удаляем результат предыдущего разбора.
				,34 => array('name'=>'insertGoods')			// добавляем найденный товары
				,35 => array('name'=>'updateRow')			// Нашли: insert! только саму строку в БД по новым значениям. state=-1
				,37 => array('name'=>'saveBarProgress')		// изменяем статус разбора
				,38 => array('name'=>'commit')
			);
			$this->_maxNumber = 38;
/*
 * @TODO: НЕ ОТКРЫВАТЬ! ТАК ПИСАТЬ НЕЛЬЗЯ! ВЫБОРКА - не всегда соответствует файлу!
			// читаем строки для апгрейда картинок из файла:
			$this->_ids = $this->_db->fetchAll(
				$this->_db->select()
					->from(array('pfr'=>PricefilesRows::TABLE), array('id', 'title'=>'gooddesc'), PricefilesRows::SCHEMA)
					->where('pfr.pfid = ?', (int)$this->_pfid, Zend_Db::PARAM_INT)
				, array()
				, Zend_Db::FETCH_ASSOC
			);
*/
		} else {
			// Инициализация автомата парсера по порядку методов обработки каждой строки:
			$this->_commands = array(
				  0 => array('name'=>'getRowXLS')			// читаем строку из файла в массив
				, 1 => array('name'=>'getWords')			// разбираем строку на слова через SQL контекстный парсер.
				, 1 => array('name'=>'findArchGoods')		// fvn-20121011: ищем аналоги строки, в общем списке всех строк.
				, 2 => array('name'=>'findGoodName')		// ищем вид товара в строке
				, 3 => array('name'=>'savePriceRow')		// сохраняем результат разбора
				, 4 => array('name'=>'getImageByUrl')		// подгрузка картинок - после сохранения строки!, если есть урл в строке.
			);
			$this->_maxNumber = 4;
		}
		$this->outMessage(Parser::DEBUG_INFO, PHP_EOL.'File consists of '. $this->_nRows .' rows, and '.
					$this->_nCols.' cols,  begining read...'.PHP_EOL
		);
		// смотрим, есть ли встроенные картинки в файле:
		$this->_imgs = $this->_sheet->getDrawingCollection();
		$this->outMessage(Parser::DEBUG_INFO, "\t found ".count($this->_imgs).' images. ');

		$this->_cntImages = count($this->_imgs);
		// fvn-20121203: Есть катринки - создаем каталог для второго прохода парсера
		if( $this->_cntImages > 0 ) $this->createImageDir();

		return Parser::OK;
	}

// ************ Парсер повторного разбора строк из БД *************** //

	/**
	 * БМУ: Заглушки для ручного управления транзакциями из БМУ:
	 * ИСПОЛЬЗОВАТЬ ОБЯЗАТЕЛЬНО! есть откат транзакций в каждой функции сохранения данных!
	 * 
	 * @param  типовые для метода БМУ
	 * @return режим перехода
	 * 
	 * @author fvn-20121207
	 */
	public function beginTransaction($row, &$data)
	{
		$this->_db->beginTransaction();
		return Parser::OK;
	}
	public function commit($row, &$data)
	{
		$this->_db->commit();
		return Parser::OK;
	}
	/**
	 * БМУ: Функция выборки одной товарной строки из набора
	 * и формирования массива данных из неё.
	 * Читает строку из предварительной выборки, чтобы не пересекаться с обновлениями
	 * и не грузить Мускуль
	 * 
	 * @param  типовые для метода БМУ
	 * @return режим перехода
	 * 
	 * @author fvn-20120115
	 */
	public function getRowSQL($row, &$data)
	{
		$data['id']        = $this->_sqlData[$row]->id;
		$data['pfid']      = $this->_pfid;
		$data['orgprow']   = $this->_sqlData[$row]->orgprow;
		$data['goodname']  = $this->_sqlData[$row]->goodname;
		$data['gooddesc']  = $this->_sqlData[$row]->gooddesc;
		$data['goodcost']  = $this->_sqlData[$row]->goodcost;
		$data['goodmunit'] = $this->_sqlData[$row]->goodmunit;
		$data['article']   = $this->_sqlData[$row]->article;
		$data['barcode']   = $this->_sqlData[$row]->barcode;
		$data['imageUrl']  = $this->_sqlData[$row]->imageUrl;
		$data['state']     = $this->_sqlData[$row]->state;
		$data['created_by']= $this->_uid;

		$this->outMessage(Parser::DEBUG_INFO, 'строка '.$row.', выбрано:'.print_r($data, true));

		return Parser::OK;
	}

	/**
	 * БМУ: сохранение текущего состояния счетчиков разбора на основе $data['state'] строки.
	 * 
	 * @param  типовые для метода БМУ
	 * @return режим перехода
	 * 
	 * @author fvn-20121207
	 */
	public function saveBarProgress( $row, &$data )
	{
		switch( (int)$data['state'] ) {
		case PricefilesRows::STATE_SUCCESS:
			++$this->barSuccess;
			break;
		case PricefilesRows::STATE_VARIANTS:
			++$this->barVariants;
			break;
		case PricefilesRows::STATE_FAILED:
			++$this->barFailed;
			break;
		}
		try {
			$this->pfModel->update(
				array('success'=>$this->barSuccess,'variants'=>$this->barVariants,'failed'=>$this->barFailed),
				'id ='.$this->_pfid
			);
			$this->outMessage(Parser::DEBUG_INFO, "...saveBarProgress updated: success={$this->barSuccess}, variant={$this->barVariants}, failed={$this->barFailed}");
		} catch (Zend_Exception $e) {
			$this->_db->rollBack();
			return $this->setError(Parser::NOT_INSERTED, 'saveBarProgress', $row, 'Обновление pfid='.$this->_pfid."\n".$e->getMessage());
		}
		return Parser::OK;
	}
	/**
	 * БМУ Метод сохранения товаров к строке.
	 * Изменяет состояние $data['state'] в зависимости от количества сохраненных товаров.
	 * $data['id'] -- номер строки для сохранения товаров!
	 * 
	 * @param  типовые для метода БМУ
	 * @return режим перехода
	 * 
	 * @author fvn-20121207
	 */
	public function insertGoods($row, &$data)
	{
		$data['state'] = ($this->_goods !== null?
			(($cntGoods=count($this->_goods)) > 1? PricefilesRows::STATE_VARIANTS : PricefilesRows::STATE_SUCCESS)
			: PricefilesRows::STATE_FAILED
		);
		$this->outMessage(Parser::DEBUG_INFO, "Сохраняем список товаров к строке: {$data['id']}, state={$data['state']}, countGoods={$cntGoods}");
		if( $this->_goods !== null && !empty($data['id']) ) {
			try {
				foreach( $this->_goods as $good ) {
					$this->pfgModel->insert(array(
						'rowid'		=> $data['id'],
						'gid'		=> $good->Good_Ident,
						'grid'		=> $good->Gr_Ident,
						'sgrid'		=> $good->SGr_Ident,
						'matching'	=> $good->cntMatch,
						'words'		=> $good->cntWords,
						'created_by'=> $this->_uid
					));
				}
			} catch (Zend_Exception $e) {
				$this->_db->rollBack();
				return $this->setError(Parser::NOT_INSERTED, 'insertGoods', $data['id'], $e->getMessage());
			}
		}
		return Parser::OK;
	}

	/**
	 * БМУ. Удаление товаров из БД для строки $data['id']. Не изменяет $data['state']!
	 * 
	 * @param  типовые для метода БМУ
	 * @return режим перехода
	 * 
	 * @author fvn-20121207
	 */
	public function deleteGoods($row, &$data)
	{
		$dels = $this->pfgModel->delete('rowid = '.(int)$data['id']);
		if( !$dels ) {
			$this->outMessage(Parser::DEBUG_WARNING,
				'Не найдены или не удалены товары из строки '.$data['id'].', state='.$data['state'].', goods='.print_r($this->_goods, true)
			);
		} else {
			$this->outMessage(Parser::DEBUG_INFO, 'Удалено старых товаров ='.$dels);
		}
		return Parser::OK;
	}

	/**
	 * БМУ. Сохранение строки переразбора обновлением
	 * 
	 * @param  типовые для метода БМУ
	 * @return режим перехода
	 * 
	 * @author fvn-20121207
	 */
	public function updateRow( $row, &$data )
	{
		try {
			if(
				! $this->pfrModel->update(
					$upd=array(
						  'pfid'       => $data['pfid']
						, 'orgprow'    => $data['orgprow']
						, 'goodname'   => $data['goodname']
						, 'gooddesc'   => $data['gooddesc']
						, 'goodcost'   => $data['goodcost']
						, 'goodmunit'  => $data['goodmunit']
						, 'article'    => $data['article']
						, 'barcode'    => $data['barcode']
						, 'imageUrl'   => $data['imageUrl']
						, 'state'      => $data['state']
						, 'updated_by' => $this->_uid
						, 'updated_at' => date('Y-m-d H:i:s')
					), 'id = '.(int)$data['id']
			)) {
				// не ошибка! добавилась точно такая же запись. Возвращает 0, если такую уже обрабатывал. Кеширует PDO!
				$this->outMessage(Parser::DEBUG_WARNING, "\tNot updated row={$row}. IS duplicate? Data:".print_r($upd, true));
//				$this->_db->rollBack();
//				return $this->setError(Parser::NOT_INSERTED, 'updateRow', $row,
//					'Ошибка при обновлении строки '.$data['id'].', прайс pfid='.$this->_pfid.' data:'.print_r($data, true));
			}
		} catch (Zend_Exception $e) {
			$this->_db->rollBack();
			return $this->setError(Parser::NOT_INSERTED, 'updateRow', $row,
					'Исключение при записи строки: '.$e->getMessage().', rowId='.$data['id'].', pfid='.$this->_pfid
			);
		}
		$this->outMessage(Parser::DEBUG_INFO, "... update row {$data['id']}: ".print_r($upd, true));
		return Parser::OK;
	}

	/**
	 * БМУ. Сохранение строки переразбора вставкой. Возвращает номер строки в $data['id'] !
	 * , меняет статус строки в $data['state'] по текущему состоянию списка товаров $this->_goods.
	 * 
	 * @param  типовые для метода БМУ
	 * @return режим перехода, $data['id'] = lastInsertId()
	 * 
	 * @author fvn-20121210
	 */
	public function insertRow( $row, &$data)
	{
		$data['state'] = ($this->_goods !== null?
			(count($this->_goods) > 1? PricefilesRows::STATE_VARIANTS : PricefilesRows::STATE_SUCCESS)
			: PricefilesRows::STATE_FAILED
		);
		try {
			$this->outMessage(Parser::DEBUG_INFO, "\tsaving row: pfid={$data['pfid']}\n ".print_r($data, true)."\n");

			//unset($data['goodname']);	//fvn-20121210: уже не будет. Убран. не надо уже! иначе js на клиенте будет ошибаться при удалении слов из вида товара!
			if( $this->pfrModel->insert($data) ) {
				$data['id'] = $this->_db->lastInsertId($this->pfrModel->info('name'));
			} else {
				$this->_db->rollBack();
				return $this->setError(Parser::NOT_INSERTED, 'insertRow', $row, 'Ошибка при записи строки. pfid='.$this->_pfid);
			}
		} catch (Zend_Exception $e) {
			$this->_db->rollBack();
			return $this->setError(Parser::NOT_INSERTED, 'insertRow', $row,
					'Ошибка: '.$e->getMessage().', pfid='.$this->_pfid
			);
		}
		return Parser::OK;
	}
	/**
	 * Перезапуск парсера по заданному набору.
	 * 
	 * @param string $state = {'success'|'variants'|'failed'}
	 * return true|error 
	 */
	public function initModeRestart($state = null) {

		if( !$state ) { $state = $this->_state; }
		switch($state) {
			case 'start':		$state = PricefilesRows::STATE_SAVED;		break;
			case 'success':		$state = PricefilesRows::STATE_SUCCESS;		break;
			case 'variants':	$state = PricefilesRows::STATE_VARIANTS;	break;
			case 'failed':		$state = PricefilesRows::STATE_FAILED;		break;
			default: return $this->setError(Parser::NOT_STATE, 'initModeRestart');
		}

		if( !($this->_sqlData = $this->pfrModel->fetchAll(
			$where = 'pfid ='.$this->_pfid.' AND state='.$state
		))) {
			return $this->setError(Parser::NOT_READED, 'initModeRestart');
		}
		$this->_startRow = 0;
		$this->_nRows = count($this->_sqlData);

		$this->_commands = array(
			  0 => array('name'=>'getRowSQL')
			, 1 => array('name'=>'findGoodName')
			, 2 => array('name'=>'beginTransaction')
			, 3 => array('name'=>'deleteGoods')
			, 4 => array('name'=>'insertGoods')
			, 5 => array('name'=>'updateRow')
			, 6 => array('name'=>'saveBarProgress')
			, 7 => array('name'=>'commit')
		);
		$this->_maxNumber = 7;
		$this->outMessage(Parser::DEBUG_INFO, "\n Выбрано ".$this->_nRows.' строк. Из '.print_r($where,true));
		return Parser::OK;
	}

	/**
	 * Удаление разобранных изображений к прайсовым строкам файла:
	 * удаляет каталог с каринтками прайса для переразбора, если нет опубликованных строк на портале.
	 *
	 * @param int $pfid == номер прайса
	 * @return bool -- если false - то нельзя переразбирать - есть опубликованные строки на портале!!!
	 * @todo ПЕРЕДЕЛАТЬ! Это неверно. Уже есть новые таблички и могут быть архивные строки...
	 */
	public function delImageFiles($pfid) {
		$isPublic = $this->imgModel->fetchRow(
/*die((string)*/			$this->imgModel->select()->setIntegrityCheck(false)
				->from(array('im'   => Images::TABLE), array('isPublic'=>'SUM(IF(im.prices_hash != "",1,0))'), Images::SCHEMA)
				->join(array('prim' => PricefilesImages::TABLE),'prim.pr_images_id = im.id','',PricefilesImages::SCHEMA)
				->join(array('row'  => PricefilesRows::TABLE),'row.id = prim.rowid','',PricefilesRows::SCHEMA)
				->where('row.pfid = ?', $pfid, Zend_Db::PARAM_INT)
		);
		if( $isPublic && !$isPublic->isPublic ) {
			$dir = SITE_DOCUMENT_ROOT .ImageUtils::IMG_DIR. Pricefiles::IMAGE_DIR . $pfid;
			ImageUtils::removeDirectory($dir);
			return true;
		}
		return false;
	}
	/**
	 * Создает каталог для последующей загрузки изображений при разборе прайсового файла.
	 * Вызывается или при первом обнаружении урла картинки к прайсовой строке, или при втором проходе
	 * при заливке картинок, интегрированных в файл.
	 * 
	 * @author fvn-20121203: выделено из self::initModeXLS(), @see self::getImageByUrl()
	 */
	public function createImageDir()
	{
		$imageDir = SITE_DOCUMENT_ROOT . ImageUtils::IMG_DIR . Pricefiles::IMAGE_DIR . $this->_pfid;
		if( !is_dir($imageDir) ) {
			if( !mkdir($imageDir, 0775) ) {
				return $this->setError(
					Parser::NOT_CREATE_DIR, 'parser', 0,
					"Cannot create '".$imageDir."'!"
				);
			}
		}
		$this->_imageDir = Pricefiles::IMAGE_DIR . $this->_pfid . '/';
	}
	/**
	 * Сохраняет картинку, заданную ресурсом PHP_GD в файлы для последующего использования на портале
	 * , при ошибке сохранения оригинала - уменьшает текущий номер изображения к строке.
	 * ВАЖНО: описание картинки и номер строки привязки берется
	 *   ИЛИ: из списка сохраненных строк использовать только ПОСЛЕ сохранения строки разбора!
	 *   ИЛИ: @see rev.12313: Добавлен параметр - признак, что нет данных в массиве, брать из $data
	 *        , только для прямого вызова метода! @see $this->getUpdateRow()
	 *
	 * @param  resource(PHP_GD) $im     -- картинка в виде ресурса (из строки или файла или как ещё).
	 * @param  array            $imData -- формат PHP_GD::getimagesize()
	 * @param  int              $row    -- номер строки разбора (файла) для которой сохраняется картинка
	 * @param  int              $rowId  -- НЕ ДЛЯ БМУ! номер записи
	 * @param  string           $title  -- ... и описание брать тут - прямой вызов!
	 * @return int
	 * 
	 * @author fvn-20121010: выделено из $this->_afterParse() для добавления картинок из УРЛ при построчной обработке.
	 * @see $this->afterParse(), $this->getImageByUrl()
	 */
	public function saveImage($im, $imData, $row, $rowId = null, $title = '')
	{
		if( !isset($rowId) ) { $rowId = $this->_ids[$row]['id']; $title = $this->_ids[$row]['title']; }

		$this->_imgsCounts[$rowId] = ( isset($this->_imgsCounts[$rowId]) ? $this->_imgsCounts[$rowId]+1 : 0);
		if( $this->_imgsCounts[$rowId] > $this->_maxAllowedImages ) { return false; }

		$origName = SITE_DOCUMENT_ROOT .ImageUtils::IMG_DIR. $this->_imageDir . $rowId.'_'.$this->_imgsCounts[$rowId] . '.' . (substr($imData['mime'], 6));
		if( !ImageUtils::gd_image_out($im, $imData['mime'], $origName) ) {
			$this->outMessage(Parser::DEBUG_WARNING,
				'Warning! Cannot save image for priceRow='.$rowId.', filename='.$origName.' continued.'
			);
			$this->_imgsCounts[$rowId]--;
			return false;
		}
		// добавляем в БД картинок и масштабируем картинку:
		$imgId = $this->imgModel->addNew($origName, $this->_imageDir, $rowId, $this->_imgsCounts[$rowId], '', $title);
		unlink($origName);

		if ( is_array($imgId) ) {
			$this->outMessage(Parser::DEBUG_WARNING,
				'Warning! Can\'t create '.$imgId['message'].' image for '.$rowId.', errCode='.$imgId['error'].'!'
			);
		} elseif(
			!$this->pfrImageModel->insert(array(
				'pr_images_id'=>$imgId,
				'rowid'=>$rowId,
				'status' => 1,
				'created_by' => $this->_uid
		))) {
			$this->outMessage(Parser::DEBUG_WARNING, 'Warning! Can\'t save image for row='.$rowId.'!');
			return false;
		}
		$this->outMessage(Parser::DEBUG_INFO, "\t\t image ".$origName .', cell('.$row.'), ids='.$rowId.'- was uploaded');
		return $imgId;
	}

	/**
	 * Построчный парсинг строк прайсового файла.
	 * версия 1.
	 * 
	 * @return int
	 * @see cron/parsepf.php - фоновый процесс! 
	 */
	protected function _beforeParse() {
		$res = Parser::OK;

		if ( !$this->_uid || !$this->_fid || !$this->_pfid ) {
				echo 'parser: Неверные входные данные. pfid='.$this->_pfid.', fid='.$this->_fid.', uid='.$this->_uid;
				return Parser::STOP;
		}
		$list = $this->pfModel->getList((int)$this->_pfid);
		$xls = $list[0];
		if (!isset($xls) || count($list)>1 )	return $this->setError(Parser::NOT_PFID, '_beforeParse()', 0, 'list='.print_r($list, true));
		$this->barSuccess = $this->barVariants = $this->barFailed = 0;	// прогресс-бары текущего разбора...

		switch($this->_state) {
			case 'success':
			case 'variants':
			case 'failed':
				$res = $this->initModeRestart();
				break;
			// перепарсинг заново (удаляем всё что было):
			case 'start':
				// fvn-20121204: добавлен режим обновления прайса: @see self::initModelXLS()
				$delRows = 0;
				$where = 'id='.$this->_pfid;
				if( empty($this->_update) || (int)$this->_update != (int)$this->_pfid ) {
					if( !$this->delImageFiles($this->_pfid) ) {
						return $this->setError(Parser::ERR_IS_PUBLIC, '_beforeParse');
					}
					$delRows = $this->pfrModel->delete('pf'.$where);
				}
				$this->outMessage(Parser::DEBUG_INFO, ' обновлены счетчики: ' . $this->pfModel->update(
						array('success'=>0,'variants'=>0, 'failed'=>0), $where
					).
					'. Удаление старых, удалено:'.($delRows)
				);
				$res = $this->initModeXLS($xls);
				break;
			default:
				return $this->setError(Parser::NEW_STARTED, '_beforeParse');
		}
		if($res != Parser::OK) return $res;
		$this->pfModel->setState($this->_pfid, $this->_uid, self::STATE_LOCKED);
		return Parser::OK;
	}

	/**
	 * Запускает второй цикл разбора картинок для варианта разбора из файла xls
	 * 
	 * @see application/modules/default/models/Parser#_afterParse($res, $row, $num, $data)
	 */
	protected function _afterParse($res, $row, $num, $data=null) {

		if( $res >= Parser::STOP ) { return $res; }

		// fvn-20121218: удаление необновленных строк, если надо: updMode {2,3} @see /cabinet/views/scripts/prices/loadprice.phtml
		if( isset($this->_update) && (int)$this->_update == (int)$this->_pfid && ((int)$this->_updMode & 2) ) {
			$this->outMessage(Parser::DEBUG_INFO, 'Удаление необновленных строк. Удалено '
				. $this->pfrModel->delete(
						'pfid = '.(int)$this->_pfid.' AND updated_at < "'.date('Y-m-d H:i:s', $this->_startTime).'"'
				) . ' шт.'
			);
		}
		// Обработка изображений, если они были в файле:
		$this->outMessage(Parser::DEBUG_INFO, 'найдено '.$this->_cntImages.' картинок.');
		if( $this->_cntImages > 0 ) {

			$this->_db->beginTransaction();
			foreach( $this->_imgs as $img ) {
				$cell = $img->getCoordinates();
				$cellRow=(int)substr($cell, 1);			 // пропускаем ОДНУ! букву с колонкой (пока достаточно)
				$rowId = $this->_ids[$cellRow]['id'];
				$this->outMessage(Parser::DEBUG_INFO, 'cell='.$cell.', rowId='.$rowId);

				if (is_a($img, 'PHPExcel_Worksheet_Drawing')) {

					$imData = getimagesize($img->getPath());
					$im = &ImageUtils::gd_image_create($img->getPath(), $imData['mime']);
					$ext = substr($imData['mime'], 6);

				} elseif(is_a($img, 'PHPExcel_Worksheet_MemoryDrawing')) {

					$im = $img->getImageResource();

			        switch( $img->getRenderingFunction() ) {
			        case PHPExcel_Worksheet_MemoryDrawing::RENDERING_JPEG:
			            $imData = array('mime'=>'image/jpeg');
			            $ext = 'jpg';
			            break;
			        case PHPExcel_Worksheet_MemoryDrawing::RENDERING_GIF:
			            $imData = array('mime'=>'image/gif');
			            $ext = 'gif';
			            break;
			        case PHPExcel_Worksheet_MemoryDrawing::RENDERING_PNG:
			        case PHPExcel_Worksheet_MemoryDrawing::RENDERING_DEFAULT:
			            $imData = array('mime'=>'image/png');
			            $ext = 'png';
			            break;
			        }
				} else {
					$this->outMessage(Parser::DEBUG_WARNING, 'Warning! New image class from PHPExcel!!! cell='.$sell);
				}
				$this->outMessage(Parser::DEBUG_INFO, 'imgClass='.get_class($img).', ext='.$ext.', data='.print_r($imData, true));

				// fvn-20121203: считаем, проверяем количество и сохраняем, если уже была - ДОБАВЛЯЕМ:
				$this->saveImage($im, $imData, $cellRow);
			}
			$this->_db->commit();
		}
		$this->pfModel->update(array(
				'notes'=>'Разобрано за '.(time()-$this->_startTime).' сек.'
			), 'id='.$this->_pfid
		);
		$this->pfModel->setState($this->_pfid, $this->_uid, self::STATE_PARSED);
		return Parser::OK;
	}

	/**
	 * Завершение разбора строки прайса/БД. Освобождаем память.
	 * 
	 * @param  int   $res
	 * @param  int   $row
	 * @param  array $data
	 * @return int
	 * 
	 * @author fvn-20121207
	 * @see /default/models/Parser->_endParseRow()
	 */
	protected function _endParseRow($res, $row, &$data) {
		unset($this->_goods); unset($this->_words);
		return Parser::OK;
	}
}
?>