# Liveblog Firebase

Integrates Firebase Cloud Messaging (FCM) into the liveblog module.

This module contains the FirebaseNotificationChannel plugin, which acts as an 
app server to send data messaged to a client app via the FCM connection 
server using HTTP.

It also implements a JavaScript client app, which handles the data messages,
only supported for these browsers:

    Chrome: 50+
    Firefox: 44+
    Opera Mobile: 37+

# Restrictions

There is a payload limit of 4KB for data messages & 2KB for notifications.

Although both notification and data messages can be send through FCM, this
module only implements data messages which are processed by the client app.
