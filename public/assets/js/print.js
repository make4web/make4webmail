document.addEventListener('click', function (e) { if (e.target.closest('[data-print]')) window.print(); });
window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });
