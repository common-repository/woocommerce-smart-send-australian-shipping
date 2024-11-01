jQuery(document).ready(function ($) {
    const eventMethod = window.addEventListener
        ? "addEventListener"
        : "attachEvent";
    const eventer = window[eventMethod];
    const messageEvent = eventMethod === "attachEvent"
        ? "onmessage"
        : "message";

    eventer(messageEvent, function (e) {
        if (e.data === "app:refresh" || e.message === "app:refresh"){
            window.location.reload(true);
        }
    });
});