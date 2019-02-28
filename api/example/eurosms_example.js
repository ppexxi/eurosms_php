/**
 * Javascript for EuroSMS API Example. | Javascript pre Príklad použitia EuroSMS API.
 */
window.onload = function() {
    document.getElementById('recip').focus();

    // apply translations for all elements | aplikovať preklady pre všetky elementy
    var lang = window.euroSmsLanguage;
    if (lang) {
        var strings = {};
        var elems = document.querySelectorAll('*');
        for (var i = 0; i < elems.length; i++) {
            var elem = elems[i];

            var tagName = elem.tagName.toLowerCase();

            if ((['texarea', 'script', 'style'].indexOf(tagName) < 0) && !elem.children.length) {
                var text = elem.innerHTML.trim();
                strings[text] = text;

                var trans = lang[text];
                if (trans) {
                    elem.innerHTML = trans;
                }
            }

            var text = (elem.getAttribute('placeholder') || '').trim();
            strings[text] = text;

            var trans = lang[text];
            if (trans) {
                elem.setAttribute('placeholder', trans);
            }

            var tagType = (elem.type || '').toLowerCase();
            if (['submit', 'reset', 'button'].indexOf(tagType) >= 0) {
                var text = (elem.value || '').trim();
                strings[text] = text;

                var trans = lang[text];
                if (trans) {
                    elem.value = trans;
                }
            }
        }

        console.log(JSON.stringify(strings));
    }
}


/**
 * Translate text | Preložiť text
 *
 * @param string string String to translate. | Reťazec na preklad.
 *
 * @return string Translated string. | Preložený reťazec.
 */
function t(string) {
    return window.euroSmsLanguage[string] || string;
}

/**
 * Submit message form | Odoslať formulár so správou.
 *
 * @return void
 */
function sendMessage() {
    var frm = document.forms['eurosms'];

    if (!frm.checkValidity()) {
        return;
    }

    if (confirm(t('Do you want to send message?'))) {
        frm.submit();
    }
}

/**
 * Clear form inputs | Vymazať vstupné polia formulára
 *
 * @return void
 */
function clearInputs() {
    var frm = document.forms['eurosms'];

    for (var i in frm.elements) {
        var elem = frm.elements[i];

        var tagName = elem.tagName;
        if (!tagName) {
            continue;
        }

        tagName = tagName.toLowerCase();

        elem.checked = false;

        if ((['select'].indexOf(tagName) < 0) && (['button', 'reset', 'checkbox'].indexOf(elem.type) < 0)) {
            elem.value = '';
        }

        if (tagName == 'textarea') {
            elem.innerHTML = '';
        }
    }
}