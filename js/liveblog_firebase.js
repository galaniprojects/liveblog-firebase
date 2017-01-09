(function($, Drupal, drupalSettings) {
  Drupal.behaviors.liveblogFirebase = {

    /**
     * Drupal attach method.
     *
     * @param context
     */
    attach: function(context) {
      var me = this;
      var settings = drupalSettings.liveblog_firebase;

      Drupal.behaviors.liveblogStream.getContainer(context)
        .once('liveblog-firebase-initialised')
        .each(function(i, element) {
          var liveblogStream = Drupal.behaviors.liveblogStream.getInstance(context);

          console.log('Initialize Firebase.');
          console.log(settings);

          firebase.initializeApp({
            messagingSenderId: settings.sender_id
          });

          const messaging = firebase.messaging();

          me.registerServiceWorker()
            .then(function() {
              me.subscribe(settings.topic);
            });

          // Handle incoming messages.
          messaging.onMessage(function(message) {
            console.log("Message received. ", message);
            var data = message.data;
            var event = message.data.event;
            var msgTopic = message.data.topic_id;

            if (settings.topic != msgTopic) {
              console.log('other topic');
              return;
            }

            console.log(event, data);

            if (event == 'add') {
              liveblogStream.addPost(data);
            } else if (event == 'edit') {
              liveblogStream.editPost(data);
            }
          });

          // Handle token refresh.
          messaging.onTokenRefresh(function() {
            messaging.getToken()
              .then(function(refreshedToken) {
                console.log('Token refreshed.');
                me.subscribe(settings.topic);
              })
              .catch(function(err) {
                console.log('Unable to retrieve refreshed token ', err);
              });
          });

          console.log('initialize firebase DONE');
        })
    },

    /**
     * Register service worker.
     *
     * @returns {Deferred}
     */
    registerServiceWorker: function() {
      var defer = $.Deferred();
      var settings = drupalSettings.liveblog_firebase;
      const messaging = firebase.messaging();

      if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register(settings.sw_file).then(function(registration) {
          messaging.useServiceWorker(registration);
          // Registration was successful
          console.log('ServiceWorker registration successful with scope: ', registration.scope);
          defer.resolve();

        }).catch(function(err) {
          // registration failed :(
          console.log('ServiceWorker registration failed: ', err);
          defer.reject(err);
        });
      }
      else {
        defer.resolve();
      }

      return defer;
    },

    /**
     * Subscribe to a topic.
     *
     * @param {string} topic
     *    The topic to subscribe to.
     */
    subscribe: function(topic) {
      var me = this;
      const messaging = firebase.messaging();
      console.log("subscribe: ", topic);

      messaging.requestPermission()
        .then(function() {
          console.log('Notification permission granted.');
          messaging.getToken()
            .then(function(currentToken) {
              if (currentToken) {
                console.log("current token: ", currentToken);
                me.requestSubscription(currentToken, topic);
              } else {
                console.log('No Instance ID token available. Request permission to generate one.');
              }
            })
            .catch(function(err) {
              console.log('An error occurred while retrieving token. ', err);
            });
        })
        .catch(function(err) {
          console.log('Unable to get permission to notify.', err);
        });
    },

    /**
     * Send subscription request to the app server.
     *
     * @param {string} token
     *    The current token string.
     * @param {string} topic
     *    The topic to subscribe to.
     */
    requestSubscription: function(token, topic) {
      console.log("request subscription: ", token, topic);
      $.ajax({
        url: '/liveblog_firebase/subscribe/' + token +'/' + topic,
        success: function(result) {
          console.log('Subscribed to ' + topic);
        },
        error: function(result, status, error) {
          console.log('Error subscribing to ' + topic, result, status, error);
        }
      });
    }
  }

})(jQuery, Drupal, drupalSettings)