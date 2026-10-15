/*! Make4WebMail — online editor page (office server over WOPI) */
(function () {
  'use strict';
  var box = document.querySelector('[data-office]');
  if (!box) return;
  var origin = box.getAttribute('data-origin');
  var frame = document.getElementById('m4w-office-frame');
  // The access token travels in a POST body, never in a URL.
  document.getElementById('m4w-office-form').submit();
  function post(msg) { try { frame.contentWindow.postMessage(JSON.stringify(msg), origin); } catch (e) { /* not ready */ } }
  window.addEventListener('message', function (e) {
    if (e.origin !== origin) return;
    var m;
    try { m = typeof e.data === 'string' ? JSON.parse(e.data) : e.data; } catch (x) { return; }
    if (!m || !m.MessageId) return;
    if (m.MessageId === 'App_LoadingStatus' && m.Values && m.Values.Status === 'Frame_Ready') post({ MessageId: 'Host_PostmessageReady' });
    if (m.MessageId === 'UI_Close' || m.MessageId === 'close') {
      if (window.top !== window) window.parent.postMessage({ m4w: 'editor-close' }, window.location.origin);
      else window.location.href = box.getAttribute('data-back');
    }
  });
})();
