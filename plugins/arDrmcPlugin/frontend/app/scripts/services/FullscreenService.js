(function () {

  'use strict';

  angular.module('drmc.services').service('FullscreenService', function ($document, $rootScope) {

    var document = $document.get(0);

    $document.bind('fullscreenchange mozfullscreenchange webkitfullscreenchange', function () {
      $rootScope.$broadcast('fullscreenchange', {
        type: document.fullscreenElement || document.mozFullScreenElement || document.webkitFullscreenElement ? 'enter' : 'exit'
      });
    });

    return {

      all: function () {
        this.enable(document.documentElement);
      },

      enable: function (element) {
        if (element.requestFullscreen) {
          element.requestFullscreen();
        } else if (element.webkitRequestFullscreen) {
          element.webkitRequestFullscreen();
        } else if (element.mozRequestFullScreen) {
          element.mozRequestFullScreen();
        } else if (element.webkitRequestFullScreen) {
          element.webkitRequestFullScreen();
        }
      },

      cancel: function () {
        if (document.exitFullscreen) {
          document.exitFullscreen();
        } else if (document.webkitExitFullscreen) {
          document.webkitExitFullscreen();
        } else if (document.mozCancelFullScreen) {
          document.mozCancelFullScreen();
        } else if (document.cancelFullScreen) {
          document.cancelFullScreen();
        } else if (document.webkitCancelFullScreen) {
          document.webkitCancelFullScreen();
        }
      },

      isEnabled: function () {
        return document.fullscreenElement || document.mozFullScreenElement || document.webkitFullscreenElement;
      }

    };

  });

})();
