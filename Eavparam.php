<?php
namespace app\models;

use Yii;
use yii\helpers\Html;
use yii\base\Exception;

/**
 * Class Eavparam -- Модель работы с именованием параметров для EAV модели деталей запроса(лида).
 *
 * @package app\models
 * @author fvn-20170419
 *
 * @property int    eavId      -- Ид. параметра EAV
 * @property int    status     -- статус записи: живая, удаленная и т.д.
 * @property int    created    -- дата создания timestamp
 * @property string updated    -- дата/время последнего обновления записи
 * @property int    updater    -- кто последний правил запись
 * @property int    idParent   -- родительский параметр (группа)
 * @property bool   isParent   -- это группа?
 * @property int    tagValType -- тип значений параметра
 * @property int    iOrder     -- порядковый номер в общем списке параметров
 * @property string strParname -- имя EAV параметра описания
 */
class Eavparam extends BaseModel
{
    /** Группы(классы, шаблоны) наборов параметров */
    const CLASS_MAIN = null;
    const CLASS_USER = 1;
    const CLASS_USER_NAME = 'Данные о пользователях';
    const CLASS_LEAD = 2;
    const CLASS_LEAD_NAME = 'Запросы (лиды)';

    static public $pkey = 'eavId';

    /** Текстовки типов значений параметров */
    public static $valTypes = [
        Tags::VALS_GROUP    => 'группа',
        Tags::VALS_INT      => 'Целые числа',
        Tags::VALS_DOUBLE   => 'Вещественные числа',
        Tags::VALS_STRING   => 'Текст',
        Tags::VALS_DATE     => 'Даты',
        Tags::VALS_TIME     => 'Время',
        Tags::VALS_DATETIME => 'Дата+время',
    ];

    /** Yii: "название таблицы" */
    public static function tableName()
    {
        return '{{%_eavs}}';
    }

    /** Yii: "Наименования полей для автоформ " */
    public function attributeLabels()
    {
        return [
            'eavId'      => '№п.п.',
            'status'     => 'статус',
            'author'     => 'автор',
            'created'    => 'создано',
            'updater'    => 'корректор',
            'updated'    => 'изменено',
            'idParent'   => 'предок',
            'isParent'   => 'есть предок?',
            'iOrder'     => 'порядок',
            'strParname' => 'имя',
            'tagValType' => 'тип'
        ];
    }

    /** Yii: "правила валидации" записей */
    public function rules()
    {
        return [
            [['iOrder','strParname','tagValType'], 'required'],
            [['eavId', 'status','author','updater','iOrder','tagValType','isParent'], 'integer'],
            [['idParent','created','updated'], 'safe'],
            [['strParname'], 'string', 'max' => 255],
        ];
    }

    /** Yii: выборка параметров класса (М-М) */
    public function getClassesParams()
    {
        return $this->hasMany(static::class, ['idParent' => 'eavId'])->with('classesParams');
    }

    /**
     * переназначение типового поля 'id' на primary key этой модели:
     * @return \yii\db\ActiveQuery
     */
    public static function sqlBy($filter)
    {
        if( isset($filter['id']) ){ $filter['eavId'] = $filter['id']; unset($filter['id']); }
        return parent::sqlBy($filter);
    }

    /**
     * Возвращает список объектов с вложением выборки из связаных таблиц для показа текстовок в виде дерева.
     *
     * @param int $id -- если надо по одной/массиву записей, иначе все
     *
     * @return array tree
     */
    public static function &findList( $id=null )
    {
        $filter = ['with'=>['classesParams'], 'asArray'=>true];
        if( !empty($id) )
            $filter['eavId'] = $id;
        else
            $filter['idParent'] = ['isNull'=>true];

        $rows = static::findBy($filter);
        return Arrayutils::makeTree($rows, 'eavId', 'classesParams');
    }

    /**
     * Пересобирает деревянный массив параметров, собирая только листья в линейный массив
     *
     * @param array  $tree
     * @param string $prefix
     * @return array
     */
    static public function getOnlyLists($tree, $prefix = '')
    {
        $lpList = [];
        foreach($tree as $num=>$lp){
            if( $lp['isParent'] ){
                $lpList = array_merge($lpList, static::getOnlyLists($lp['classesParams'], $prefix.$lp['strParname']));
            } else {
                $tree[$num]['strParname'] = $prefix.$tree[$num]['strParname'];
                $lpList[$lp['eavId']] = $tree[$num];
            }
        }
        return $lpList;
    }

    /**
     * Возвращает список всех параметров группы (без неё!) в массив с индексацией по eavId
     *
     * @param string $group -- текстовка strParname для поиска группы
     * @return array
     * @throws \Exception
     */
    public static function &getListAll($group)
    {
        $leadRows = static::findAll(['strParname'=>$group]);
        if( empty($leadRows) ){
            throw new \Exception('ERROR! Eavparam::getListAll():: Не задана группа для поиска её параметров - нельзя вывести списки параметров в формах..');
        }
        $pars = static::getOnlyLists(
            static::findBy(['idParent'=>$leadRows[0]->eavId, 'with'=>'classesParams', 'asArray'=>true])
        );
//die(var_dump($pars));
        $lpList = [];
        foreach($pars as $num=>$lp){ $lpList[$lp['eavId']] = $pars[$num]; }

        return $lpList;
    }

    /**
     * Возвращает список подготовленный для генерации опций селектора параметров с формированием составного
     * ключа опции (передача в список типа значений и порядкового номера параметра с его идентом)
     *
     * @param string $group
     * @return array
     * @throws \Exception
     */
    public static function &getOptions($group)
    {
        $leadRows = static::findAll(['strParname'=>$group]);
        if( empty($leadRows) ){
            throw new \Exception('ERROR! Eavparam::getListAll():: Не задана группа для поиска её параметров - нельзя вывести списки параметров в формах..');
        }
        $pars = static::findBy(['idParent'=>$leadRows[0]->eavId, 'with'=>'classesParams', 'asArray'=>true]);
        $lpList = static::getOnlyLists($pars);

        return Arrayutils::toArray(
            $lpList, 'strParname', ['eavId'=>['','-'], 'tagValType'=>['','-'], 'iOrder'=>['','']], ['0-0-0'=>'выберите параметр']
        );
    }

    /**
     * Возвращает массив для where() с правильной подстановкой поля выборки по типу параметра
     *
     * @param $tagValType
     * @param $value
     * @return array
     */
    public static function getWhere($tagValType, $value){
        switch( $tagValType ){
            case Tags::VALS_INT:      return ['valInt'    => $value];
            case Tags::VALS_DOUBLE:   return ['valDouble' => $value];
            case Tags::VALS_STRING:   return ['valString' => $value];
            case Tags::VALS_TIME:     return ['valDate'=>'0000-00-00 '.$value];
            case Tags::VALS_DATE:     return ['valDate'=>$value.' 00:00:00'];
            case Tags::VALS_DATETIME: return ['valDate'=>$value];
            default:
                return [];
        }
    }

    // ========================================== методы ОБЪЕКТОВ =================================================== //
    /**
     * Yii: переопределение .. устранение ошибок заполнения idParent типовым методом
     *
     * @param array $data
     * @param null $formName
     * @return bool
     * @throws Exception
     */
    public function load($data, $formName = null)
    {
        $d = &$data['Eavparam'];
        if( empty($d['idParent']) || $d['idParent'] === 'null' ){ $d['idParent'] = null; }

        return parent::load($data, $formName);
    }

    /**
     * Возвращает тип значения по значению
     *
     * @param $val
     * @return int
     * @throws Exception
     */
    static public function getValType($val){
        if( is_array($val)       ){ return Tags::VALS_GROUP;    }
        else if( is_double($val) ){ return Tags::VALS_DOUBLE;   }
        else if( is_int($val)    ){ return Tags::VALS_INT;      }
        else if( is_date($val)   ){ return Tags::VALS_DATE;     }
        else if( is_time($val)   ){ return Tags::VALS_TIME;     }
        else if( is_dtime($val)  ){ return Tags::VALS_DATETIME; }
        else if( is_string($val) ){ return Tags::VALS_STRING;   }
        else {
            throw new Exception('ERROR! Новый тип параметров, непонятно куда сохранять значения..');
        }
    }

    // ============================= методы отображения сущностей из массивов ======================================= //

    /**
     * Типовой метод для возврата через AJAX HTML-вывода отображения 1 эелемента модели (добавление, правка)
     *
     * @param  array  $item
     * @return string HTML
     */
    public static function showOne($item)
    {
        return static::showItem($item, 1, true);
    }

    /**
     * Вывод списка элементов (под)дерева
     *
     * @param array $tree
     * @return string
     */
    public static function showTree($tree, $level=0)
    {
        $content = '';
        $maxNum = count($tree);
        $num=0;
        foreach($tree as $node){
            $content .= static::showItem($node, $level, ++$num == $maxNum);
        }
        return $content;
    }

    /**
     * Отдает контент вывод элемента дерева в дивной верстке с рекурсивным вызовом поддеревьев, если таковые есть внутри
     *
     * @param array   $item
     * @param int     $level
     * @param bool    $isLast
     * @return string
     */
    public static function showItem($item, $level, $isLast)
    {
//die(var_dump($item));
        $path = '@web/img/tree/';
        $treeTag = '';
        $treeOptions = ['width'=>'24px;', 'height'=>'24px;'];
        if( $level>0 ){
            $treeTag = str_repeat(Html::img($path.'i.gif', $treeOptions), $level-1);
            $treeTag .= ( $isLast? Html::img($path.'L.gif', $treeOptions) : Html::img($path.'t.gif', $treeOptions) );
        }
        $start = PHP_EOL.str_repeat("\t", $level);

        $order = 1;
        if (!empty($item['classesParams'])) {
            $isSubGroup = $start."\t\t".$treeTag.
                Html::button(
                    Html::img('@web/img/tree/closed.gif', ['width'=>'24px;', 'height'=>'24px;', 'class'=>'eavtree-closed']).
                    Html::img('@web/img/tree/open.gif',   ['width'=>'24px;', 'height'=>'24px;', 'class'=>'eavtree-opened', 'style'=>'display:none']),
                    [
                        'type' => 'button', 'class' => 'eavtree-btn', 'title' => 'свернуть/равернуть класс/группу',
                        'onclick' => "eavTreeToggle({$item['eavId']})"
                    ]) . '-'
            ;
            $subTree = $start."\t\t".self::showTree($item['classesParams'], $level+1);
            $order += count($item['classesParams']);
        } else {
            $isSubGroup = $treeTag.Html::tag('span', '-------');
            $subTree = '';
        }
        $subTree = Html::tag('div',$subTree,['id'=>'sub-'.$item['eavId'], 'style'=>'display:none;']);

        // Если параметр не группа - ++ заменять на пропуск той же ширины вместо кнопки!
        if( $item['tagValType'] == Tags::VALS_GROUP ){
            $addButton = $start."\t\t".Html::button('++', ['type'=>'button', 'class'=>'eavtree-btn eavtree-add', 'title'=>'Добавить параметр/группу сюда..',
                'onclick'=>"eavShowAddForm($(this), {$item['eavId']}, {$order})", 'style'=>'width:2em;'
            ]);
        }else{
            $addButton = $start."\t\t".Html::button('&nbsp;', ['type'=>'button', 'class'=>'eavtree-btn eavtree-add', 'title'=>'Добавить параметр/группу сюда..',
                    'onclick'=>"eavShowAddForm($(this), {$item['eavId']}, {$order})", 'disabled'=>'disabled', 'style'=>'width:2em;'
                ]);
        }
        if( !isset($item['idParent']) ){ $item['idParent'] = '\'null\''; }
        $buttons = $addButton.
            $start."\t\t".Html::button('&nbsp;x&nbsp;', ['type'=>'button', 'class'=>'eavtree-btn eavtree-del', 'title'=>'Удалить этот элемент полностью',
                'onclick'=>"eavDeleteItem({$item['eavId']})"
            ]).
            $start."\t\t".Html::button('изм', ['type'=>'button', 'class'=>'eavtree-btn eavtree-upd', 'title'=>'Изменить название элемента..',
                'onclick'=>"eavShowUpdateForm($(this), {$item['idParent']}, {$item['eavId']}, {$item['iOrder']})"
            ])
        ;
        return
            $start.Html::tag('div',
                $start."\t".Html::tag('div',
                    $start."\t\t".$isSubGroup.'-'.$buttons.'-'.
                    $start."\t\t&quot;".Html::tag('span', $item['strParname'], ['class'=>'eavtree-parname']).'&quot;'.
                    $start."\t\t(".
                        Html::tag('span', $item['strToData'], ['class'=>'eavtree-strToData']).
                        ':'.
                        Html::tag('span', self::$valTypes[$item['tagValType']], ['class'=>'eavtree-desc'])
                    .')'.
                    $start."\t\t".Html::tag('span', $item['tagValType'], ['class'=>'eavtree-valtype', 'style'=>'display:none;'])
                    , ['id'=>'data-'.$item['eavId']]
                ).
                $start."\t".$subTree,
                [
                    'id'=>'item-'.$item['eavId'],
                    'style'=>'width:800px;'
                ]
            );
    }

    /**
     * Выводит содержимое поля модели данных
     *
     * @param $name  -- имя поля
     * @param $value -- значение
     * @param $pref  -- отступ для html-вывода
     *
     * @return string
     */
    public static function showField($name, $value, $pref)
    {
        return
            $pref.Html::tag('span', $value, ['class'=>'eavtree-item']).
            $pref.Html::input('text', $name, $value, ['style'=>'display:none;', 'class'=>'eav-'.$name])
        ;
    }

    /**
     * @return string html:<option>.. для селектора парметров форм
     */
    public static function showOptions($params)
    {
        return Html::dropDownList('strParname', null, $params, ['class'=>'form-select', 'title'=>'Выберите имя параметра..']);
    }

    /**
     * Рекурсивное сохранение массива параметров [pname=>pvalue],
     * ! если pvalue массив, то pname - имя группы параметров
     * ! пропуск создания нового параметра в группе для числовых индексов - сохранение значений под именем группы как
     *   набора значений под один параметр .. умеет хранить списки значений ..
     *
     * Контроль наличия параметра в системе!
     *
     * @param array  $params   -- набор параметров-значений для последующего сохранения в БД
     * @param int    $idParent -- родитель этой кучки параметров
     * @param string $prefix   -- название группы параметров, если в массиве тупо перечисление значений
     * @throws Exception
     * @return array -- [idParam,tagValType,val..] -- построчно по каждому параметру исходного массива, кроме групп
     * @TODO: Сделать транзакционно! или блокировать табличку параметров от внешнего доступа ..
     */
    static public function &saveArray($params, $idParent, $prefix)
    {
//var_dump($params); // для отладки раскомментировать ВСЁ, включая die() в блоке вызова этой функции - рекурсия!!
        $res = [];
        $vals = [];
        foreach( $params as $pName=>$val ){
//echo "\n<br/>pName={$pName}";
//var_dump($val);
//echo "\n<br/>";
            $parName = $pName;
            if( is_int($pName) ){
                // Если не имя, а номер в массиве:
                if( is_array($val) || is_object($val) ){
                    throw new Exception('ERROR! Eavparam::saveArray() Не могу сохранить массив/объект как элемент массива ..');
                }
                $vals[] = $val;
//echo "\n<br/>added to vals {$val}, continued.";
                continue;
            }
            // ищем параметр по имени:
            $param = Eavparam::find()
                ->where(['idParent' => $idParent, 'strParName' => $parName, 'status' => 0])
                ->one();
            if( empty($param) ){
//echo "\n<br/>Не нашли..
                // НОВЫЙ: поиск последнего номера списка параметров:
//";
                $db = Yii::$app->get('db');
                $row = $db->createCommand("SELECT MAX(iOrder) AS max FROM leads._eavs WHERE idParent = {$idParent}")->queryOne();

                if( empty($row) || empty($row['max']) ){
                    $iOrder = 1;
                } else {
                    $iOrder = $row['max'] + 1;
                }
//echo "\n<br/>iOrder={$iOrder}";
                // определяем тип данных по значениям к параметру:
                $tagValType = static::getValType($val);
//echo "\n<br/>tagValType={$tagValType}";
                // сохраняем новый параметр с нумерацией префикса, если числовой индекс значения в группе:
                $param = new Eavparam([
                    'status'     => 0,
                    'author'     => User::USER_BOT,
                    'updater'    => User::USER_BOT,
                    'idParent'   => $idParent,
                    'isParent'   => $tagValType == Tags::VALS_GROUP? 1 : 0,
                    'iOrder'     => $iOrder,
                    'strParname' => $parName,
                    'tagValType' => $tagValType
                ]);
                $param->save();
            }else{
//echo "\n<br/>Нашли params:";
//var_dump($param->getAttributes());
            }

            if( $param->tagValType == Tags::VALS_GROUP ){
                if( !empty($val) ) {
//echo "\n<br/>Recursion:
                    // Это группа и она не пуста! Рекурсивно формируем список новых параметров для нечисловых индексов группы:
//";
                    if( !is_array($val) ){ $val = [$val]; }
                    $res = array_merge($res, static::saveArray($val, $param->eavId, $param->strParname));
//echo "\n<br/>Выход из рекурсии:";
//var_dump($res);
//echo "\n<br/>";
                }
            }else{
//echo "\n<br/>простой параметр:
                // формируем выход: тип значения разложится при сохранении согласно tagValType - перекрыто!
//";
                $res[] = [
                    'idParam'    => $param->eavId,
                    'strParname' => $param->strParname,
                    'tagValType' => $param->tagValType,
                    'value'      => $val
                ];
//var_dump($res);
            }
        } // end foreach
//echo "\n<br/>Конец цикла смотрим vals";
        // Упс. Если собрали список значений, то это была группа с массивом для самой группы - добавляем:
        if( !empty($vals) ){
//var_dump($vals);
            $res[] = [
                'idParam'    => $idParent,
                'strParname' => $prefix,
                'tagValType' => Tags::VALS_GROUP,
                'value'      => $vals
            ];
        }
//var_dump($res);
        return $res;
    }
}
