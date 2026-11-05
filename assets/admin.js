(function () {
  'use strict';
  window.addEventListener('load', function () {
    var notice = document.querySelector('.dpda-result');
    if (notice) { window.setTimeout(function () { notice.focus(); }, 0); }
  });
}());
