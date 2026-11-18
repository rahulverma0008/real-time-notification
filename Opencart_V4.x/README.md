# Real Time Notification

Shows "Alice from London just bought ..." notifications to the visitors of the store (social proof).

**For OpenCart 4.0.0.0 - 4.1.0.4** (one package). For OpenCart 3.x / 2.x use the other folders.

## How To Install
1) In your OpenCart admin panel go to Extensions > Installer
2) Upload `real_time_notification.ocmod.zip` and click the green install button
3) Go to Extensions > Extensions, choose Modules from the extension type
4) Click the Install button of Real Time Notification, then the edit button
5) Choose the position and the order statuses which count as a sale, enable the module and save

The script is added to the footer of every page with an OpenCart event (catalog/view/common/footer/after): no OCMOD, no file of OpenCart is changed.
Every visitor asks for new sales every 5 seconds, shows the sales of the last 10 minutes on the first request.

## Features
1. Easy to install and setup
2. Helps to improve sales and engage visitors
3. Real time notifications (AJAX)
4. Responsive (works on mobile and desktop)
5. Position, delay, dismiss, pause on hover, newest on top and progress bar settings

Privacy: the notification shows the first name and the city of the customer. Tell your customers about it in your privacy policy,
or remove `{{city}}` / `{{firstname}}` from the text in the language file.

## What is new in 2.0.0 (compared with the OpenCart 3 version)
**Bugs fixed**
* Every notification showed the buyer of the *first* sale (the text was overwritten inside the loop and not reset): the second and later sales now have their own name and city.
* Orders without a payment address were never announced. The payment address is optional in OpenCart 4 (off by default); the shipping city is used, and
  an order without any city is announced without "from ...".
* "Newest On Top" and "Progress Bar" could not be switched off (an off switch was treated like "never saved" and turned on again).
* Customer names, cities and product names are escaped before they are shown to all visitors; the order status list is cast to numbers in the SQL.
* A PHP notice printed in front of the JSON answer no longer stops the notifications.

**Changes**
* OpenCart 4.0.0.0 - 4.1.0.4 in one package, Bootstrap 5 markup (the old bootstrap-toggle library of the admin is not needed anymore).
* Settings keep their names (`module_real_time_notification_*`) and are saved with the OpenCart 4 AJAX form with validation.

## Testing
`_oc-test-harness/run.sh real-time-notification/Opencart_V4.x --oc <version> --fresh` (from the parent folder) installs the zip in a real OpenCart,
seeds real orders and checks the notifications (who / what / where, escaping, status and store filter, per visitor cursor), the footer, the
settings, and shows a real notification in headless Chrome (needs node, chrome and puppeteer-core, see the Stripe Pro tests).
