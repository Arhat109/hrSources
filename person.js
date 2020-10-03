/**
 * JS-code for pers_form.php and other Person model
 *
 * Created by fvn on 26.06.17.
 */
var person = {
    selfName: 'person',
    options: {},
    objPrefix: '#Person-',
    fieldPrefix: '.Person-',
    listSelector: '.persons',
    formSelector: '#personForm',
    errorSelector: '#persErrors',
    urlSave: '/person/submit',
    urlDelete: '/person/delete?id=',
    titleCreate: 'Новая персона',
    titleUpdate: 'Правка персоны',
    btnCreate: 'создать персону',
    btnUpdate: 'сохранить правку',
    isConfirm: false,
    fields:['tId','sex','family','name','otchestvo','birthday','comment'],

    isValid: { name: false },
    validate     : function(obj, name, type, pattern)
    {
        this.isValid[name] = fvn.validate(obj, type, pattern);
        return fvn.validateSubmit( this );
    },
    /** callback() из внешних страниц модального использования формы: заменять "вне" на то что нужно! */
    afterSave: function (data) {},

    newContact: function(obj, tId, options){
        personContact.to = obj.parent().find(personContact.listSelector);
        personContact.afterSave = function(data){
            if( !data.error ){
                personContact.clearMain(obj.parent(), data.data);
                personContact.to.append(data.data);
            }
        };
        fvn.create(personContact, obj, {tId:tId}, options);
    },
    /** open form for add new contact for this */
    newDoc: function(obj, tId, options){
        personDoc.to = obj.parent().find('.persDocs');
        personDoc.afterSave = function(data){
            if( !data.error ){
                personDoc.to.append(data.data);
            }
        };
        fvn.create(personDoc, obj, {tId:tId}, options);
    },
    /** open form for add new contact for this */
    newUser: function(obj, tId, options){
        personUser.to = obj.parent().find('.persUsers');
        personUser.afterSave = function(data){
            if( !data.error ){
                personUser.to.append(data.data);
            }
        };
        fvn.create(personUser, obj, {tId:tId}, options);
    },
    /** open form for add new contact for this */
    newFile: function(obj, tId, options){
        personFile.to = obj.parent().find('.persFiles');
        personFile.afterSave = function(data){
            if( !data.error ){
                personFile.to.append(data.data);
                $('#persFileForm').hide();
            }
        };
        fvn.create(personFile, obj, {tId:tId}, options);
    },
    /** (общая открывалка окон пересена в fvnlib.js) открывалка окна детальной инфы: контакты, документы и пр. */
    openedDetail: false,
    showDetail: function(where, obj, offset){
        var pos = where.offset();

        if( obj.css('display') == 'none' ){
            if( this.openedDetail ){ this.openedDetail.hide(); }
            obj.show(350).offset({left: pos.left +offset.left, top: pos.top + offset.top});
            this.openedDetail = obj;
        }else{
            obj.hide(350);
            this.openedDetail = false;
        }
    },
    /** перейти к заявке этой персоны */
    toZays: function()
    {
        var zId = $('[name="zays"]').val();

        if( zId > 0 )          { window.location = '/zays/' + zId; }
        else if( zId == 'new' ){ window.location = '/zayavka/prepare?tId=' + $('[name="tId"]').val(); }
    },
/* ===== !!! дополнение работы с подчиненными сущностями по требованию Бондаренко "сделать как там в точности" ===== */
/* переведено в aria-.. схему работы со списком через CSS
    showDropDown: function(obj, name){
        var pos = obj.offset();

        $('#'+name).show().css('width', obj.css('width')).offset({left:pos.left, top:pos.top + 32});
        obj.removeClass('btn-update').addClass('btn-default');
        this.fromObj = obj;
    },
*/
    saveUser: function(uId, tId, opts){
        $('#PersProfileList').hide();
//        person.fromObj.removeClass('btn-default').addClass('btn-update');
        $.post('/person/user-submit', {data: {tId: tId, uId: uId, _csrf: _csrf}, options: opts},
            function(res){
                if( res.ok && $('.Person-users #PersonUser-'+res.json.puId).length == 0 ){
                    $('.Person-users').append(res.data);
                }
            }, 'json'
        );
    },
    saveProfile: function(){
        person.formSelector = '#PersForm';
        person.afterSave = function(data){
                $('#PersTagForm [name="tId"]').val(data.json.tId);
                $('#PersForm [name="tId"]').val(data.json.tId);
                person.tId = data.json.tId;
                personTag.afterSave = function(data){
                    window.location = '/person/update?id='+person.tId;
                };
                fvn.save(personTag, $('#PersTagForm'), false);
        };
        fvn.toValidate(person);
        fvn.save(person, $(person.formSelector), false);
    }
};