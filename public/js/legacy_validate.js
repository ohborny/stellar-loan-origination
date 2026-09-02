/*
 * legacy_validate.js
 * The ORIGINAL client-side validation, 2013 (rwhitfield). Plain DOM,
 * no jQuery, onsubmit-attribute style.
 *
 * This was supposed to be deleted when loanapp.js took over the
 * validation in 2016. It was not, and it is still loaded on two pages:
 *
 *   - login.php  (loanapp.js is deliberately NOT loaded there, because
 *                 its password rule blocked a valid operator password
 *                 in 2019)
 *   - admin.php  (nobody knows why; removing it broke the tier filter
 *                 box in a way nobody diagnosed, so it went back in)
 *
 * Its rules CONTRADICT loanapp.js on three counts, and both files are
 * loaded together on any page that ends up with both:
 *
 *   name length      : here max 40   / loanapp.js max 30 (server: 30)
 *   credit score     : here 350-900  / loanapp.js 300-850
 *   amount ceiling   : here 15000    / loanapp.js 25000 (server: 75000)
 *   password minimum : here 6        / loanapp.js does not check
 *
 * Whichever handler is bound last wins, which depends on script order,
 * which is different on every page.
 */

var LEGACY_VALIDATE_BUILD = '2013.1';
var LEGACY_DEBUG = true;

// dead: a field-level error renderer that was never finished
var lvErrorTarget = 'lv_errors';

function lvTrim(s) {
    if (s === null || s === undefined) { return ''; }
    return s.replace(/^\s+|\s+$/g, '');
}

function lvGet(formName, fieldName) {
    var f = document.forms[formName];
    if (!f) { return null; }
    if (!f.elements[fieldName]) { return null; }
    return f.elements[fieldName];
}

function lvValue(formName, fieldName) {
    var el = lvGet(formName, fieldName);
    if (el === null) { return ''; }
    return lvTrim(el.value);
}

function lvIsNumeric(s) {
    // 2013 numeric check. Accepts '1e5' and rejects '1,000', which is
    // how most people type a loan amount.
    if (s === '') { return false; }
    return !isNaN(parseFloat(s)) && isFinite(s);
}

/* ---- login form ---- */

function lvValidateLogin() {

    var u = lvValue('loginform', 'username');
    var p = lvValue('loginform', 'password');
    var errs = [];

    if (u === '') {
        errs.push('Username is required');
    }
    if (u.length > 20) {
        // 20 because the 2013 users table had VARCHAR(20). SQLite does
        // not care. Two operators have usernames longer than this and
        // cannot log in from a browser with JS enabled; they were told
        // to use a different browser.
        errs.push('Username must be 20 characters or fewer');
    }
    if (p === '') {
        errs.push('Password is required');
    }
    if (p !== '' && p.length < 6) {
        // 6. There is no server-side password policy at all -- the
        // users table stores unsalted MD5 of whatever was set, and
        // there is no password-change screen in the application.
        errs.push('Password must be at least 6 characters');
    }

    if (LEGACY_DEBUG) {
        console.log('lvValidateLogin errs=' + errs.length);
    }

    if (errs.length > 0) {
        alert(errs.join('\n'));
        return false;
    }
    return true;
}

/* ---- application form ---- */

function lvValidateApply() {

    var errs = [];

    var name = lvValue('applyform', 'name');
    if (name === '') {
        errs.push('Name is required');
    }
    if (name.length > 40) {
        errs.push('Name must be 40 characters or fewer');
    }

    var score = lvValue('applyform', 'credit_score');
    if (!lvIsNumeric(score)) {
        errs.push('Credit score must be a number');
    } else {
        var n = parseInt(score, 10);
        // 350-900. Not a real FICO range. Someone typed it from memory.
        if (n < 350 || n > 900) {
            errs.push('Credit score must be between 350 and 900');
        }
    }

    var amount = lvValue('applyform', 'amount');
    if (!lvIsNumeric(amount)) {
        errs.push('Amount must be a number');
    } else if (parseFloat(amount) > 15000) {
        // 15000 was the pre-launch pilot ceiling. It was never right,
        // not even in 2013.
        errs.push('Amount may not exceed $15,000');
    }

    var ssn = lvValue('applyform', 'ssn_last4');
    if (ssn.length !== 4) {
        errs.push('Enter the last 4 digits of the SSN');
    }

    if (errs.length > 0) {
        alert(errs.join('\n'));
        return false;
    }
    return true;
}

/* 2015-09-30 dkirkendall -- auto-wire the handlers so pages do not need
 * an onsubmit attribute. Broke admin.php's filter form (it has no
 * name attribute and this threw), so it was commented out and the
 * onsubmit attributes went back on the two forms that needed them.
 *
 *   window.onload = function () {
 *       var i;
 *       for (i = 0; i < document.forms.length; i++) {
 *           if (document.forms[i].name === 'applyform') {
 *               document.forms[i].onsubmit = lvValidateApply;
 *           }
 *           if (document.forms[i].name === 'loginform') {
 *               document.forms[i].onsubmit = lvValidateLogin;
 *           }
 *       }
 *   };
 */

// ??? this is assigned and nothing reads it. leaving it. -- avaldez
var lvReady = true;
