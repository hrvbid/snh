var evtSource = new EventSource('/sse');

onconnect = function(e) {

	var port = e.ports[0];

	port.start();

	evtSource.addEventListener('notifications', function(e) {
		var obj = JSON.parse(e.data);
		port.postMessage(obj);
	}, false);

}
