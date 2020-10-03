/*
 * Скрипт подсчета времени пребывания пользователя на странице портала.
 * @author fvn-20120323 v.1
 */
var mediamStatHash = '';
var mediamStatCount = 0;
var mediamTime0 = 0;
var mediamTime1 = 0;
var mediamStatMax = 0;
var mediamStatTimer = null;
var mediamStatUrl = '';

function sendTimer(p) {
	var r = false;

	if (window.XMLHttpRequest) {
		r = new XMLHttpRequest();
	} else if (window.ActiveXObject) {
		try { 
			r = new ActiveXObject("Msxml2.XMLHTTP");
		} catch(e) {
			try {
				r = new ActiveXObject("Microsoft.XMLHTTP");
			} catch(e) { }
		}
	}
    if (!r) return false;

    r.open('POST', mediamStatUrl, true);
    r.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
    r.setRequestHeader("Accept","application/json");
   // r.setRequestHeader("Content-length", p.length);
    r.setRequestHeader("Connection", "close");
    r.setRequestHeader("X_REQUESTED_WITH", "XMLHttpRequest");
    r.send(p + '&rnd='+Math.random());
}

function mediamTimer() {
	clearTimeout(mediamStatTimer);
	if( ++mediamStatCount <= mediamStatMax ) {
		sendTimer('h=' + mediamStatHash + '&c=' + mediamStatCount + '&t='+mediamTime1);
		if( mediamStatCount % 2 == 0) {
			mediamTime1 = mediamTime1 + mediamTime1;
		}
		mediamStatTimer = setTimeout(mediamTimer, mediamTime1);
	}
}

function stat2mediam(data) {

	mediamStatCount = 0;
	mediamStatUrl = data.statUrl;

	sendTimer('h=' + (mediamStatHash=data.hash) + '&d=1' + '&sw=' + screen.width + '&sh=' + screen.height
		+ '&t=' + (mediamTime0=mediamTime1=data.period) + '&m=' + (mediamStatMax=data.maxPeriod)
	);

    window.onload = function() { mediamStatTimer = setTimeout(mediamTimer , data.period); }
}