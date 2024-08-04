=== My Tickets: Authorize.net ===
Contributors: joedolson
Donate link: https://www.joedolson.com/my-tickets/add-ons/
Tags: my-tickets, authorize.net
Requires at least: 4.9
Tested up to: 6.6
Requires PHP: 7.0
Stable tag: trunk

Support for Authorize.net in My Tickets.

== Description ==

Support for Authorize.net payment gateway transactions using My Tickets.

New or updated translations are always appreciated. The translation files are included in the download. 

== Installation ==

1. Upload the `/my-tickets-authnet/` directory into your WordPress plugins directory.

2. Activate the plugin on your WordPress plugins page
  
3. Go to My Tickets > Payment Settings and configure the Authorize.net payment gateway.

== Changelog ==

= 1.2.2 =

* Bug fix: Support cart-specific handling fees.
* Bug fix: Incorrectly queried array to get address info.
* Change: Use item_id instead of item_name for license queries.
* Change: Add payments.js to this plugin instead of getting from core.

= 1.2.1 =

* Bug fix: Link directly to license field.
* Bug fix: Correct directory name in instructions.
* Bug fix: Update EDD plugin updater class.
* Update copyright.
* Remove unused variables & sanitize early.

= 1.2.0 =

* Improved currency handling
* Constrain admin notices to My Tickets settings.
* Add admin notice to require SSL.
* Change payment method from DPM to AIM.
* Switch to use SDK classes directly.
* Update code style.

= 1.1.3 =

* Bug fix: Precautionary check for class existence
* Bug fix: check class names in Authorize.net 

= 1.1.2 =

* Bug fixes with license activation & updates

= 1.1.1 =

* Update extension to use EDD endpoints & licensing

= 1.1.0 =

* Adds support for description, phone, and alters use of email to support broader array of Authorize.net settings.
* Add additional filters for adding & processing custom fields in payment form.
* Store provided billing address
* Bug fix with URL encoding on return from Auth.net
* Bug fix with URL escaping on return from Auth.net

= 1.0.3 =

* Update Authorize.net URLs per June 2016 API change.

= 1.0.2 =

* Security fix: XSS related to add_query_arg

= 1.0.1 = 

* Add support for handling fee. 
* Avoid conflict with other plug-ins using Auth.net

= 1.0.0 =

* Initial launch.

== Frequently Asked Questions ==

= Hey! Why don't you have any Frequently Asked Questions here! =


== Screenshots ==

== Upgrade Notice ==
