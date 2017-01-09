# Liveblog Firebase

Integrates Firebase Cloud Messaging (FCM) into the liveblog module.

This module contains the FirebaseNotificationChannel plugin, which acts as an 
app server to send data messaged to a client app via the FCM connection 
server using HTTP.

It also implements a JavaScript client app, which handles the data messages.
Since this is a Service worker app these pages must be served over HTTPS, and 
are only supported by these browsers:

    Chrome: 50+
    Firefox: 44+
    Opera Mobile: 37+

# Restrictions

There is a payload limit of 4KB for data messages & 2KB for notifications.

Although both notification and data messages can be send through FCM, this
module only implements data messages which are processed by the client app.

# Local development & testing

### Secure host is needed for FCM & when registering a service worker

Create a self signed certificate:

    a2enmod ssl
    a2ensite default-ssl
    openssl req -x509 -nodes -days 365 -newkey rsa:2048 -keyout my.key -out my.crt
    # modfiy sites-enabled/default-ssl.conf and add your cert key & file.
    # @see /usr/share/doc/apache2/README.Debian.gz
    /etc/init.d/apache2 restart

Run chrome with this argument, otherwise service-workers will not be registered:

    google-chrome --ignore-certificate-errors

### Uninstall a service worker

If you change the service worker, you need to uninstall it in the browser, so
the new version will be loaded. You can unregister the worker from there: 

    chrome://serviceworker-internals/
