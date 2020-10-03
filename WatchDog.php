<?php
/**
 *  Модель сторожа изменений в системе
 *
 * @author fvn-20170601
 * @TODO: Можно настройки перенести в БД и привязать их к конкретным юзверям. Тогда у каждого юзверя будет свой комплект данных для сторожа изменений.
 */

namespace app\models;

use yii\base\Exception;

class WatchDog
{
    const TIME_PERIOD = 15; // 5сек. между обращениями одного и того же юзверя @see /themes/assests/js/main.js -- править и там тоже!

    /**
     * @var array $watchControlled -- список наблюдаемых объектов в системе для каждого пользователя
     * Формат: класс => [
     *   'var'     => {'jsModelName' | [..]} -- метка js-модели или список таковых или пусто,
     *   'depends' => {...} -- аналогично, "зависит от модели(ей)"
     *   'find'    => ['dt'=>поле_модели_временной метки, 'who'=>поле_кому_ищем, 'with'=>[...], 'asArray'=>bool]
     * ]
     */
    public static $watchControlled = [
        'app\\models\\LeadNote' => [
            'var'     => 'note',
            'depends' => [],
            'find'    => ['dt'=>'created', 'who'=>'idManager', 'with'=>['atrAuthor','atrManager']],
        ],
        'app\\models\\Lead'     => [
            'var'     => 'lead',
            'depends' => ['note'],
            'find'    => ['dt'=>'updated', 'who'=>'idManager', 'with'=>['atrAuthor','atrManager','atrTagContact','atrTagSource','relEavs','lastNote']],
        ]
    ];
    /**
     * @var array $watchAssets [
     *   'jsModelName'=>[
     *      'html' => [0=>['file'=>путьИмя_файла, 'params'=>[параметры_к_рендерингу_файла] ]]
     *      'js'   => [0=>['file'=>путьИмя_файла, 'params'=>[параметры_к_рендерингу_файла] ]],
     *      'css'  => [0=>[..]]
     *   ],
     * ]
     * , где:
     * 'js','html' -- всегда нумерованные массивы или пусто!
     * 'params' => [ 'name'=>{string | ['action'=>'values']} ] -- дополнять по мере необходимости, где:
     *   'action' => {{'object'|'array' => [array_vals]}  -- передача параметру массива значений или объект(ов) "как есть"
     *            | 'new'=>constructor                    -- вызов конструктора без параметров
     *            | {'func'=>'fullName', 'param'=>value}  -- вызов функции/метода с 1 параметром (значение)
     *            | {'funcArray'=>fullName, 'params'=>[]} -- вызов функции/метода с массивом параметров
     */
    public static $watchAssets = [
        'note' => [
            'js'   => [
                0=>['file'=>'/lead/note.js', 'params'=>[]]
            ],
            'html' => [
                0=>['file'=>'/lead/note_form.php', 'params'=>[
                    'mode'=>'hidden', 'parent'=>['new'=>'\app\models\LeadNote'], 'model'=>['new'=>'\app\models\LeadNote']
                ]]
            ]
        ],
        'lead' => [
            'html' => [
                0=>['file'=>'/lead/lead_form.php', 'params'=>[
                    'mode'=>'hidden', 'model'=>['new'=>'\app\models\Lead'], 'allParams'=>[
                        'func'=>'\app\models\Eavparam::getOptions', 'param'=>Eavparam::CLASS_LEAD_NAME
                    ]
                ]]
            ],
            'js'  => [
                0=>['file'=>'/lead/lead_form.js', 'params'=>[]],
                1=>['file'=>'/lead/eav_form.js', 'params'=>[]]
            ]
        ]
    ];

    /**
     * Ищет последнюю метку отправки обновляшек юзверю
     * @TODO: пока только по иденту юзверя, но в дальнейшем можно расширить на страницу(группу) запроса(ов)
     *
     * @param  string $url
     * @param  string $uId
     * @return LeadWatchdog
     */
    public static function getLastMark($url, $uId)
    {
        $row = LeadWatchdog::findOne(['idManager'=>$uId]);
        return (!empty($row)? $row : new LeadWatchdog(['idManager'=>$uId, 'dtLast'=>0]));
    }

    /**
     * Обновляет заданную метку новым временем
     *
     * @param LeadWatchdog $mark
     * @param int          $newTime (UNIX TIMESTAMP)
     */
    public static function setLastTime($mark, $newTime)
    {
        /** @var User $user */
        $user = user()->getIdentity();

        $mark->dtLast = $newTime;
        $mark->author = $user->uId;
        $mark->updater = $user->uId;
        $mark->save();
    }

    /**
     * Ищет изменения в БД для сторожа со страницы. Юзверя вычисляет сам по сессии..
     *
     * @param  LeadWatchdog $mark -- метка для которой ищем обновления
     * @return \yii\db\ActiveRecord[][]
     */
    public static function getChanges($mark)
    {
        $res = [];
        foreach(self::$watchControlled as $class=>$opts){
            $find = $opts['find'];
            $filter = [$find['who']=>$mark->idManager, 'author'=>['cond'=>'<>', $mark->idManager] ];
            if( !empty($find) ) {
                if( isset($find['dt'])      ){ $filter[$find['dt']] = ['cond' => '>=', date('Y-m-d H:i:s', $mark->dtLast)]; }
                if( isset($find['with'])    ){ $filter['with'] = $find['with']; }
                if( isset($find['asArray']) ){ $filter['asArray'] = $find['asArray']; }
            }
            /** @var BaseModel $class */
            $rows = $class::findBy($filter);
            /** @var string $class */
            if( !empty($rows) ){ $res[$class] = $rows; }
        }
        return $res;
    }

    /**
     * Формирует ответ для AJAX-рендеринга контроллером в виде массива
     *
     * @param  LeadWatchdog $mark
     * @return array['html=>'', 'vars'=>[,..]]
     */
    public static function &makeAnswer($mark)
    {
        $res = ['html'=>'', 'vars'=>[]];
        $news = WatchDog::getChanges($mark);
        if (!empty($news)) {
            foreach ($news as $class => $rows) {
                /** @var BaseModel $class */
                $res['html'] .= $class::showList($rows);
                /** @var string $class */
                if( !empty(WatchDog::$watchControlled[$class]['var']) )
                {
                    $res['vars'][] = WatchDog::$watchControlled[$class]['var'];
                    if( !empty(WatchDog::$watchControlled[$class]['depends']) ){
                        foreach( WatchDog::$watchControlled[$class]['depends'] as $jsModel ){
                            $res['vars'][] = $jsModel;
                        }
                    }
                }
            }
        }
        return $res;
    }

    /**
     * Формирует список параметров для рендеринга требуемого файла, в т.ч. и исполнением действий:
     *
     * @param array $item
     * @return array
     * @throws Exception
     */
    public function &makeRenderParams($item)
    {
        $params = [];
        if( !empty($item['params']) ){
            foreach( $item['params'] as $pname=>$val){
                if( $val === null || is_bool($val) || is_int($val) || is_float($val) || is_string($val) ){
                    $params[$pname] = $val;
                }else if( is_array($val) ){
                    reset($val);           // принудительно текущий элемент на первый.
                    $type = key($val);     // ключ первого элемента - способ вычисления ("как" вычислить значение параметра)
                    $action = $val[$type]; // "что сделать этим "как", далее возможны иные элементы массива - параметры действия и т.д.
                    switch( $type ){
                        case 'new'      : $funcVal = new $action();                                 break;
                        case 'func'     : $funcVal = call_user_func($action, $val['param']);        break;
                        case 'funcArray': $funcVal = call_user_func_array($action, $val['params']); break;

                        case 'object'   :
                        case 'array'    : $funcVal = $action; break;
                        default:
                            $params = ['error'=>"ERROR! WatchDog::makeRenderParams(): Нераспознанное действие {$action} в описании параметра {$pname} для файла {$item['file']}!"];
//die(var_dump($params));
                            return $params;
                    }
                    $params[$pname] = $funcVal;
                }else{
                    throw new Exception('Watchdog::makeRenderParams(): ERROR! Новый тип параметра не знаю как быть..');
                }
            }
        }
//die(var_dump($params));
        return $params;
    }
    /**
     * Вовзращает набор [HTML,JS,CSS] комплекта текстов (рендеринг) для заданной jsModel согласно схеме:
     *
     * @param  \yii\web\Controller $controller
     * @param  string              $jsModel
     * @param  array               $schema -- optional, default WatchDog::$watchAssets
     * @return array
     */
    public static function &getJS($controller, $jsModel, $schema = [] )
    {
        if( empty($schema) ){ $schema = WatchDog::$watchAssets; }
        if( empty($schema[$jsModel]) ){ return ['error'=>'Watchdog::getJS(): ERROR! Не найдена требуемая JS-модель..']; }

        $res = [];
        foreach(['html','js','css'] as $type){
            if( !empty($schema[$jsModel][$type]) ){
                foreach($schema[$jsModel][$type] as $num=>$item){
                    if( !empty($item['file']) ) {
                        if( empty($res[$type]) ){ $res[$type] = ''; }

                        switch($type){
                            case 'html':
                                $res[$type] .= "\n<-- {$jsModel}[{$num}]={$item['file']} -->\n";
                                break;
                            case 'js':
                            case 'css':
                                $res[$type] .= "\n/** {$jsModel}[{$num}]={$item['file']} */\n";
                                break;
                        }
                        $res[$type] .= $controller->getView()->render($item['file'], static::makeRenderParams($item), $controller);
                    }
                }
            }
        }
        return $res;
    }
}
