(function($){

	$('.fDL').on('click', function(){

		$.ajax({
			type: 'POST',
			data: 'fname='+$(this).attr('id').replace("-",".")+'&_ffnonce='+$('#_wpnonce').val(),
			success: function(json){
				var j =JSON.parse(json);
				if( j.success=='ok' ){
					location.reload();
				}
			}
		});
	});

	if( location.href.indexOf('shortcode') < 0 ) return false;

	var message;
	$.getJSON('?firstform.json', function(json){
		message = json;
	});

	$('#item,#vali,#name').on('change keyup', function(){
		var t=$('#item').val(),v=$('#vali').val(),n=$('#name').val(),o=$('#code'),op='';

		$('#name').attr('placeholder', message.placeholder_name);

		switch(v){
			case 'required':
				op = ' required message="'+message.required+'"';
				break;
			case 'email':
				op = ' email tomail message="'+message.required+'"';
				break;
			case 'zip':
				op = ' zip message="'+message.zip+'"';
				break;
			case 'tel':
				op = ' tel message="'+message.tel+'"';
				break;
			case 'numeric':
				op = ' numeric message="'+message.numeric+'"';
				break;
			case 'alphanumeric':
				op = ' alphanumeric message="'+message.alphanumeric+'"';
				break;
		}
		switch(t){
			case 'text':
				o.val('[fform type=text name="'+n+'"'+op+']');
				break;
			case 'radio':
				o.val('[fform type=radio name="'+n+'" value="" label=""'+op+']');
				break;
			case 'checkbox':
				o.val('[fform type=checkbox name="'+n+'" value="" label=""'+op+']');
				break;
			case 'select':
				o.val('[fform type=select name="'+n+'" options="'+message.select+'"'+op+']');
				break;
			case 'textarea':
				o.val('[fform type=textarea name="'+n+'"'+op+']');
				break;
			case 'file':
				$('#base').val( $('#base').val().replace(/fform_area]/g, 'fform_multi_area]') );
				if( n=='' ) n='file*';
				o.val('[fform type=file name="'+n+'" ext=".jpeg,.jpg,.png,.gif" ext_message="'+message.ext+'" size=1048576 size_message="'+message.size+'"]');
				break;
			case 'submit':
				$('#name').attr('placeholder', message.placeholder_sub);
				if( n=='' ) n=message.submit;
				o.val('[fform type=submit value="'+n+'" confirm="'+message.confirm+'"]');
				break;
			case 'reset':
				$('#name').attr('placeholder', message.placeholder_sub);
				if( n=='' ) n=message.reset;
				o.val('[fform type=reset value="'+n+'"]');
				break;
			case 'button':
				$('#name').attr('placeholder', message.placeholder_sub);
				if( n=='' ) n=message.button;
				o.val('[fform type=button value="'+n+'"]');
				break;
			case 'hidden':
				o.val('[fform type=hidden name="'+n+'" value=""]');
				break;

		}
	});
	$('#addcode').on('click', function(){
		var o=$('#base'),t=$('#code').val();

		o.focus();
		if( navigator.userAgent.match(/MSIE/) ){
			var r = document.selection.createRange();
			r.text = t;
			r.select();

		}else{
			var s=o.val(),p=o.get(0).selectionStart,np=p + t.length;

			o.val(s.substr(0, p) + t + s.substr(p));
			o.get(0).setSelectionRange(np, np);
		}
	});
	$('#base').on('blur', function(){

		$.ajax({
			type: 'POST',
			data: 'base='+$('#base').val()+'&_ffnonce='+$('#_wpnonce').val(),
			success: function(){}
		});
	});

})(jQuery);