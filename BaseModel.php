<?php
namespace app\models;

use yii\db\ActiveQuery;
use yii\db\Query;

/**
 * Дополнение базовой модели Yii2 ActiveRecord универсальными методами построителя sql запросов и фильтрации данных
 * из конфигурационного массива.
 *
 * Каждая дочерняя модель может переопределять метод ::sqlBy() для доп. обработки входящего фильтра, в частности автозамена
 * типового pkey::`id` на собственное значение.
 *
 * Все модели (таблицы) наследники обязательно имеют поля:
 *
 * @property int    status  -- статус записи 0 - живая
 * @property int    author  -- User:: номер автора записи
 * @property int    updater -- User:: номер правщика записи
 * @property string created -- дата/время создания записи автром
 * @property string updated -- дата/время последнего изменения записи правщиком, если было.
 *
 * Related:
 * @property User $atrAuthor  [id, firstName, LastName] -- данные для показа
 * @property User $atrUpdater [id, firstName, LastName] -- данные для показа
 *
 * Addition properties:
 * @property string $showPrefix static - предваряющий HTML списочного показа
 * @property string $showAffix   static - завершающий HTML списочного показа
 *
 * @property string $pkey  -- имя первичного ключа таблицы @see ::showOne()
 * @property string $jsVar -- имя JS-объекта - модели на стороне клиента(браузера)
 *
 * @author fvn-20170621..20170713
 */
class BaseModel extends \yii\db\ActiveRecord
{
    const STATUS_OK = 0;
    const STATUS_DEL = 1;

    const SYM_PKEY = ':pkey:';  /** @var string -- ключ поля, которое будет ключами для getSelect() */
    const SYM_SUBKEY = '.';     /** @var string -- разделитель полей сложного доступа */

    public static $showPrefix    = '';      /** Обрамление вывода списка элементов "до" списка */
    public static $showAffix     = '';      /** Обрамление списка элементов "после" */
    public static $showDelimiter = PHP_EOL; /** Вставка "между" элементами списка */

    public static $pkey = '';
    public static $jsVar = 'baseModel';

    /** @var array -- Список методов Yii2-query builder для переноса имени метода в конфигурационный массив запроса */
    public static $yiiMethods = [
        'select','joinWith','where','andWhere','orWhere','groupBy','having','orderBy','limit','with','asArray','indexBy'
    ];

    /** @var array -- Опции показа и прочих настроек объекта моделей */
    public $options = [];

    /**
     * Yii: своя загрузка объекта из массива - перекодирование и авто-дополнение служебных полей моделей
     *
     * @param array  $data
     * @param string $formName
     * @return bool|Lead
     */
    public function load($data, $formName=null)
    {
        /** @var \app\models\User $user */
        $user = user()->getIdentity();

        $d = &$data[getClassName($this)];
        if( $this->isNewRecord ){
            // формируем правильные даты и авторов для новой записи:
            $d['updated'] = $d['created'] = date('Y-m-d H:i:s');
            $d['updater'] = $d['author'] = $user->id;
        }else{
            // модификация имеющейся:
            $d['updated'] = date('Y-m-d H:i:s');
            $d['updater'] = $user->id;
            if( !empty($d['created']) ){ unset($d['created']); }
            if( !empty($d['author'])  ){ unset($d['author']);  }
        }
        if( empty($d['status'])   ){ $d['status'] = 0; }

        return parent::load($data, $formName);
    }

    /**
     * Типовой построитель списка условий по конфигурационному массиву
     *
     * @param Query $sql
     * @param array $filter
     * @return Query
     */
    public static function makeWhere( $sql, $filter )
    {

        foreach($filter as $field=>$value){
            if( isset($value) ){
                if( !is_array($value) ){ $value = [$value]; }

                if( !empty($value['as'])    ){ $as = $value['as']; unset($value['as']); }
                else                         { $as = static::tableName();               }
                if( !empty($value['cond'])  ){ $cond = $value['cond']; unset($value['cond']); }else{ $cond = '='; }
                if( isset($value['not'])    && $value['not']    ){ $not = 'NOT '; unset($value['not']); }else{ $not = ''; }
                if( isset($value['orNull']) && $value['orNull'] ){ $orNull = $field . ' IS NULL'; unset($value['orNull']); }else{ $orNull = ''; }
                if( isset($value['isNull']) && $value['isNull'] ){ $isNull = true; unset($value['isNull']); }else{ $isNull = false; }

                if( $cond == 'BETWEEN' && count($value)==2 ){
                    $val = ' '.$value[0].' AND '.$value[1];
                }else if( count($value)>1 ){
                    $val = '("'.implode('","', $value).'")';
                    if( $cond == '=' || $cond=='IN' ){ $cond = $not.'IN'; $not = ''; }
                }else if( count($value) == 1 ){
                    $val = '"'.$value[0].'"';
                }else if( $isNull ){
                    $cond = "IS {$not}NULL";
                    $val = $orNull = '';
                }else
                    $val=''; // such as $cond='LIKE ..' and val is into cond

                $condition = "{$as}.{$field} {$cond}{$val}";
                if( $not != ''    ){ $condition = "$not ($condition)"; }
                if( $orNull != '' ){ $condition = "({$condition}) OR ({$orNull})"; }

                $sql->andWhere($condition);
            }
        }
        return $sql;
    }
    /**
     * Доработчик построителя запросов Yii2 до конфигурационного массива:
     * выделяет все спец. описания из массива c их удалением и дополняет запрос чем требуется
     *
     * @param ActiveQuery  $sql
     * @param array        $filter -- INOUT !
     * @return ActiveQuery
     */
    public static function makeSql($sql, &$filter)
    {
        foreach( static::$yiiMethods as $method ){
            if( !empty($filter[$method]) ){
                if( ($method == 'andWhere' || $method == 'orWhere') && is_array($filter[$method]) ){
                    foreach($filter[$method] as $num=>$item){
                        $sql->$method($item);
                    }
                }elseif( $method == 'joinWith' ){
                    $sql->joinWith($filter[$method]['with'],
                        empty($filter[$method]['eager'])? true : $filter[$method]['eager'],
                        empty($filter[$method]['join'])? 'INNER JOIN' : $filter[$method]['join']
                    );
                }else{
                    $sql->$method($filter[$method]);
                }
                unset($filter[$method]);
            }
        }
        // Все остальные дополнения построителя запросов:
        if( !empty($filter['join'])    ){
            foreach( $filter['join'] as $num=>$fj ){
                $join  = empty($fj['iType'])? 'INNER JOIN' : $fj['iType'];
                $params = empty($fj['params'])? [] : $fj['params'];
                $sql->join($join, $fj['table'], $fj['on'], $params);
                if( !empty($fj['where'])  ){ $sql->andWhere($fj['where']);   }
                if( !empty($fj['select']) ){ $sql->addSelect($fj['select']); }
            }
            unset($filter['join']);
        }

        /** если нет указивок по статусу - отдаем только "живые" записи! Иначе если false не включать поле в запрос вовсе - "все записи" */
        /** @var BaseModel $filter['class'] */
        $tname = (empty($filter['class'])? static::tableName() : $filter['class']::tableName()) . '.';
        if( !isset($filter['status']) ){ $sql->andWhere([$tname.'status' => 0]); }
        else if( false === $filter['status'] ){ unset($filter['status']); }

        return $sql;
    }
    /**
     * Общая затычка для методов Yii::hasOne(), Yii::hasMany()
     *
     * @param string $className -- @see Yii::BaseActiveRecord::hasOne() / hasMany()
     * @param array  $on        -- the primary-foreign key constraint: [thereKey => myKey,..]
     * @param string $isOne     -- {'one'|'many'} какой метод связи вызывать у Yii
     *
     * @return Query
     */
    public function hasAny($className, $on, $isOne='one')
    {
        // собираем дополнения, родителю требуется чистый WHERE:
        $addition = ['class'=>$className];
        foreach( static::$yiiMethods as $method ){
            if( !empty($on[$method]) ){ $addition[$method] = $on[$method]; unset($on[$method]); }
        }
        // собираем прямые указивки по полю status тоже:
        if( isset($on['status']) ){
            $addition['status'] = $on['status'];
            unset($on['status']);
        }
        $sql = ($isOne == 'one'?
              parent::hasOne($className, $on)
            : parent::hasMany($className, $on)
        );
        // Дополняем запрос чем нашлось:
        $sql = static::makeSql($sql, $addition);

        return $sql;
    }
    /** Yii: Добавлена выборка только живых подчинений
     * @param $className -- @see parent method
     * @param $on        -- дополнение: можно указывать ключ 'status' как есть, так и пойдет..
     * @return ActiveQuery
     */
    public function hasOne($className, $on)
    {
        return static::hasAny($className, $on, 'one');
    }
    /** Yii: Добавлена выборка только живых подчинений
     * @param $className -- @see parent method
     * @param $on        -- дополнение: можно указывать ключ 'status' как есть, так и пойдет..
     * @return ActiveQuery
     */
    public function hasMany($className, $on)
    {
        return static::hasAny($className, $on, 'many');
    }
    /**
     * Построитель запросов по массиву условий по принципу 'поле'=>{значение/массив, 'cond'=>'условие', 'not'=>bool, 'isNull'=>bool,..}
     * То есть: или просто значение или нумерованный массив значений, который может дополняться ассоциативными членами:
     *   string cond   -- условие (upper case only): =, IN, 'LIKE', 'BETWEEN', '>','<' etc. создает: "field cond vals"
     *   bool   orNull -- добавляет в список условий "OR (field IS NULL)"
     *   bool   not    -- добавляет к условию "NOT": "field NOT IN(vals)", "NOT (field cond val)"
     *   bool   isNull -- если указано, то для отсутствующего значения (null) создает "field is null" иначе пропускает поле фильтра
     *   string as     -- алиас к имени поля, если требуется по запросу (составной, как часть большего)
     *
     * @see self::makeSql() -- Дополнительные "поля", согласно SQL в стиле Yii2 + доп. методы Yii2:
     *   select, groupBy, havingBy, orderBy, limit, with, asArray, indexBy
     * fvn-20170808: добавлены andWhere, orWhere в виде массивов наборов условий для обработки в цикле ::makeSql()
     * fvn-20180511: добавлен joinWith с параметрами: 'with'{[]},'eager'{true},'join'{inner join} @example LeadController->actionIndex()
     *
     * + fvn-20170709:
     *   'join' =>[ 0=> [ -- нумерованный массив встраиваемых подзапросов или таблиц:
     *       'iType'=>{inner join}, 'table'=>'PersonContacts', 'on'=>['string_on1','and on string 2', ..],
     *       'where'=>.., 'select'=>.., 'params'=>[:pname=>pval]
     *   ]]
     *
     * @param array $filter [
     *     'field1'=>{val|[vals]} -- "="|IN()
     *   , 'field2'=>['cond'=>'condition', {val|[vals]}] -- условие, значение тут же штучно или нумерованы
     *   , 'select'=>{field|[field_list]}
     *   , ..
     * ]
     *
     * @return \yii\db\ActiveQuery
     */
    public static function sqlBy($filter)
    {
        $sql = static::makeSql(static::find(), $filter /*INOUT*/);
        $sql = static::makeWhere($sql, $filter);
//if(get_called_class() == 'app\models\Person') die(var_dump($sql, $filter));
        return $sql;
    }
    /**
     * Возвращает набор объектов согласно фильтру в общем-то не нужен..
     *
     * @param  array  $filter -- @see self::sqlBy($filter)
     * @return BaseModel[]
     */
    public static function findBy($filter)
    {
//if( isset($filter['join']) ) die(var_dump( $filter, static::sqlBy($filter) ));
        return static::sqlBy($filter)->all();
    }

    // ===================================== методы типовых параметров ============================================== //
    /** Yii: связка с менеджером - автором запроса */
    public function getAtrAuthor()
    {
        return $this->hasOne(User::class, ['uId' => 'author', 'status'=>false])->with(['relEavs']);
    }

    /** Yii: связка с менеджером - корректором запроса */
    public function getAtrUpdater()
    {
        return $this->hasOne(User::class, ['id' => 'updater', 'status'=>false])->with(['relEavs']);
    }

    // ===================================== методы отрисовки модели ================================================ //
    /**
     * Возвращает типовые дополнения для отрисовки типового элемента модели к ОСНОВНОМУ(!) тегу
     *
     * @param  BaseModel $item
     * @return array
     */
    public static function getShowData($item)
    {
        /** @var User $user */
        $user = user()->getIdentity();
        $onClick = !$user->isAdmin ? '' : 'onclick="$(this).children(\'.base-data\').show(); console.log(\'showed\');"';
        $class = ($item->status!=static::STATUS_DEL? 'status-ok' : 'status-del');
        $isAdmin = $user->isAdmin;
        return [$class, $onClick, $isAdmin, $user->id, $user->role];
    }
    /**
     * Метод отрисовки базовой части объекта как продолжение тега с атрибута onclick.
     * ->options[] -- доп. настройки отрисовки базовой части
     *
     * @param  BaseModel $item
     * @return string    HTML
     */
    public static function showBaseData(BaseModel $item)
    {
if( empty($item->atrAuthor) ){ echo "\n<br/>ERROR нет служебных данных тут:<pre style=\"text-align:left;\">"; var_dump($item); die('</pre>'); }
        list($class, $onClick, $isAdmin, $uId, $uRole) = static::getShowData($item);

        $created = date('d.m.Y H:i:s', strtotime($item->created));
        $updated = date('d.m.Y H:i:s', strtotime($item->updated));
        $cssPrefix = str_replace((string)__NAMESPACE__.'\\', '', get_called_class());
        $iClass = get_class($item);
        $pkey = $iClass::$pkey;
        $jsVar = $iClass::$jsVar;

        $btnUpdateOpts = empty($item->options['baseModel']['btnUpdate']['options'])?
            '{offset:{left:0, top:32}}' : $item->options['baseModel']['btnUpdate']['options'];

        return "
<div class=\"base-data\" style=\"display:none;\">
  <div class=\"{$class} desc\">
    <p>№ <span class=\"{$cssPrefix}-{$pkey} num\">{$item->$pkey}</span>, статус: <span class=\"status\">{$item->status}</span></p>
    <p>создан: <span class=\"created\">{$created}</span>, <span class=\"author\">{$item->atrAuthor->getName()}</span> (<span class=\"{$cssPrefix}-author\">{$item->author}</span>)</p>
    <p>изменен: <span class=\"updated\">{$updated}</span>, <span class=\"updater\">{$item->atrUpdater->getName()}</span> (<span class=\"{$cssPrefix}-updater\">{$item->updater}</span>)</p>
  </div>

  <button type=\"button\" class=\"btn1 btn-close\" style=\"float:right;\" title=\"Закрыть\" onclick=\"fvn.close(event, '.base-data');\"> закрыть </button>
".( !$isAdmin? '' : "
  <button type=\"button\" class=\"btn1 btn-update\" title=\"Изменить\" onclick=\"fvn.update({$jsVar}, {$item->$pkey}, {$btnUpdateOpts}, $(this).parent());\">изм.</button>
")."
  <button type=\"button\" class=\"btn1 btn-delete\" title=\"Удалить\" onclick=\"fvn.del({$jsVar}, {$item->$pkey});\"> X </button>
</div>
";
    }
    /**
     * Метод отрисовки 1 объекта.
     * ->options[] -- доп. настройки отрисовки базовой части
     *
     * @param  BaseModel $item
     * @return string    HTML
     */
    public static function showOne($item)
    {
        $class = empty($item->options['baseModel']['class'])? 'div-span' : $item->options['baseModel']['class'];
        $style = empty($item->options['baseModel']['style'])? '' : "style=\"{$item->options['baseModel']['style']}\"";
        $onClick = empty($item->options['onclick'])? '' : " onclick=\"{$item->options['onclick']}\"";

        return "
\t<div class=\"num {$class}\" {$style} {$onClick}>
\t\t<button iType=\"button\" class=\"btn1 btn-close\" title=\"Управление и служебная информация записи..\"
\t\t\tonclick=\"$(this).parent().children('.base-data').show();\"> ? </button>

".static::showBaseData($item)."
\t</div>";

    }
    /**
     * Возвращает HTML блок для показа набора объектов
     *
     * @param  \yii\db\ActiveRecord[] $rows
     * @param  array                  $options -- доп. опции вывода
     *   options['method']       -- метод отрисовки элемента
     *   options['prefix']       -- преамбула перед списком: заданная или по умолчанию класса
     *   options['affix']        -- пост обрамление вывода: аналогично
     *   options['delimiter'     -- вставка между элементами
     *   options['isFrame'] bool -- обрамлять префиксом и аффиксом или нет
     *   options['item']         -- опции отрисовки элемента
     *
     * @return string                 HTML-UL
     */
    public static function showList($rows, $options = [])
    {
//die(var_dump($options));
        if( empty($options['method'])     ){ $method = 'showOne';                 }else{ $method = $options['method'];       }
        if( !isset($options['prefix'])    ){ $prefix = static::$showPrefix;       }else{ $prefix = $options['prefix'];       }
        if( !isset($options['affix'])     ){ $affix  = static::$showAffix;        }else{ $affix  = $options['affix'];        }
        if( !isset($options['delimiter']) ){ $delimiter = static::$showDelimiter; }else{ $delimiter = $options['delimiter']; }
        if( !isset($options['isFrame'])   ){ $isFrame = true;                     }else{ $isFrame = $options['isFrame'];     }
        if( empty($options['item'])       ){ $iOpts = [];                         }else{ $iOpts = $options['item'];          }
        if( !empty($options['a'])         ){ $iOpts['a'] = $options['a'];         }

        $content = $isFrame? $prefix : '';
        $num = 0;
        foreach($rows as $num=>$item){
//if( $method == 'showTR') die(var_dump($options, $item));
            $item->options = $iOpts;
            $item->options['num'] = $num+1;
            if( $num++ > 0 ){ $content .= $delimiter; }
            $content .= static::$method($item);
        }
        return $content . ($isFrame? $affix : '');
    }
    // ======================================= Прочие удобные методы  =============================================== //
    /**
     * Возвращает список для HTML select->option[]? выбирая заданные поля и собирая из них текстовки как требуется
     *
     * @param array  $showAs -- массив описания "как" построить строку из выбранных полей [
     *                          ':pkey:'=>'field_pkey', 'firstName'=>['before','after'], 'lastName'=>['','']]
     *
     * @return array
     *
     * @see /views/zays/partials/order/pay.html -- все прелести тут!
     */
    public static function getSelect($filter, $showAs)
    {
        $alias = static::tableName().'.';
        $pkey = $showAs[static::SYM_PKEY];
        unset($showAs[static::SYM_PKEY]);

        // правила в $showAs подлежат перестройке по индексам! формируем по отдельности ключи и правила..
        $fields = array_keys($showAs);
        $vals = [];
        // зачищаем сложные поля сборки результата:
        $select = [$alias.$pkey];
        foreach( $fields as $key=>$f ) {
            $vals[] = $showAs[$f];
            if (($pos = strpos($f, static::SYM_SUBKEY)) !== false) { // если правило из подчиненной части выборки:
                $fields[$key] = substr($f, 0, $pos);                 // убираем признак и хвост (восттанавливаем имя поля)
                continue;                                            // отменяем перенос в список полей выборки
            }
            // формируем поле с алиасом таблицы (иначе никак при дубликатах полей с джойнами!)
            $select[] = static::tableName().'.'.$f;
        }
        if( isset($filter['select']) ){ $filter['select'] = array_merge($filter['select'], $select); }
        else                          { $filter['select'] = $select; }

        if( !isset($filter['orderBy']) ){ $filter['orderBy'] = $pkey; }

        return Arrayutils::toKeyVals( static::findBy($filter), array_combine($fields, $vals), $pkey );
    }

    /**
     * Превращает массив объектов в простой массив полей записей
     * !!! если пришел уже массив - отдаем его без изменений..
     * @see ApiController::actionSearch() для поиска АПИ-счетов через Order.php - из АПИ приходит массив не объектов!
     *
     * @param  BaseModel[]|[] $rowset
     * @return array
     */
    public static function toArrayAttributes($rowset)
    {
        if( is_array($rowset) ){ return $rowset; }
        $res = [];
        foreach($rowset as $key=>$row){
            $res[$key] = $row->getAttributes();
        }
        return $res;
    }

    /**
     * Возвращает или заданный объект или создает новый, если нет ключа
     *
     * @param array  $data
     * @return static @someObject
     */
    public static function findToSubmit($data)
    {
        if( !empty($data[static::$pkey]) ){ $founded = static::findOne((int)$data[static::$pkey]); }
        if( empty($founded)              ){ $founded = new static(); }

        return $founded;
    }

    /** уникальный фильтр по умолчанию - по первичному ключу модели */
    public static function getUniqueFilter($data)
    {
//die(var_dump($data));
        return [ static::$pkey => $data[static::$pkey]];
    }

    /**
     * сохраняем как новый объект или модифицируем имеющийся по заданному правилу класса
     *
     * @param array $data -- новые параметры объекта ..
     * @return false|BaseModel
     */
    public static function saveIsNewItem($data)
    {
        $rows = static::findBy( static::getUniqueFilter($data) );
        unset($data[static::$pkey]); // м.б. нужен для поиска уника, но излишен далее..

        if( empty($rows) ){
            $item = new static(); // не найден - новый:
        }else{
            if( count($rows)>1 ){
                die(var_dump('ERROR IN DB! Найдено более одного объекта в БД этого типа!', $data));
// закрыто для отладки .. вдруг обнаружится..                throw new Exception();
            }
            $item = $rows[0];
        }
        $class = getClassName($item);
        if( $item->load([$class=>$data]) ){
            $item->status = 0;     // мог быть удален, а в новых параметрах отсутствовать..
            if( $item->save() ){
                return $item;
            }
        }
        return false;
    }

    /**
     * Перестановка заданного поля fkey в заданном списке записей моделей..
     *
     * @param string      $fname -- имя поля внешнего ключа модели
     * @param mixed       $fkey  -- новое значение ключа
     * @param BaseModel[] $list  -- изменяемый список записей
     *
     * @return array -- ['oldId'=>'newId'] список соответствия старых идентов новым.
     *
     * @see Person->mergeWith()
     */
    public static function mergeSubitems($fname, $fkey, $list)
    {
        $ids = [];
        foreach($list as $row){
            $data = $row->getAttributes();
            if( isset($row->comment) ){
                // Если есть поле комментария, то добавляем:
                $data['comment'] = 'merged tId='.$row->tId.', pdId='.$row->pdId.', was:'.$row->comment;
            }
            $data[$fname] = $fkey;
            $saved = static::saveIsNewItem($data);
            if( $saved->{static::$pkey} != $row->{static::$pkey} ){
                $row->status = static::STATUS_DEL;
                $row->update();
            }
            $ids[$row->{static::$pkey}] = $saved->{static::$pkey};
        }
        return $ids;
    }
    // =========================================== пример фильтра =================================================== //
    /**
     * Проверка работы построителя запросов, заодно и пример, если забылось чего..
     *
     * @return array|\yii\db\ActiveRecord[]
     */
    public static function example()
    {
        $vals = [];
        return self::sqlBy([
            'name'     => 'Ольга',
            'surname'  => ['orNull'=>true, 'Осипова','Бирюлина','Рудакова'],
            'birthday' => array_merge(['isNull'=>true], $vals),
            'idManager'      => ['cond'=>'>=', 'not'=>true, 'orNull'=>true, 10]
        ])->all();
    }
}