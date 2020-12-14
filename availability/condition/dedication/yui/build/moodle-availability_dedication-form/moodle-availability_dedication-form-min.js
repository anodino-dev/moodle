YUI.add('moodle-availability_dedication-form', function (Y, NAME) {

/**
 * JavaScript for form editing date conditions.
 *
 * @module moodle-availability_dedication-form
 */
M.availability_dedication = M.availability_dedication || {};

/**
 * @class M.availability_dedication.form
 * @extends M.core_availability.plugin
 */
M.availability_dedication.form = Y.Object(M.core_availability.plugin);

/**
 * Initialises this plugin.
 *
 * @method initInner
 */
M.availability_dedication.form.initInner = function() {
    // Does nothing.
};

M.availability_dedication.form.instId = 1;

M.availability_dedication.form.getNode = function(json) {
    "use strict";
    var html, root, node, id;

    id = 'dedication' + M.availability_dedication.form.instId;
    M.availability_dedication.form.instId += 1;

    // Create HTML structure.
    html = '';
    html += '<label for="' + id + '">';
    html += M.util.get_string('fieldlabel', 'availability_dedication') + ' </label>';
    html += ' <input type="text" name="dedication" id="' + id + '">';
    node = Y.Node.create('<span>' + html + '</span>');

    // Set initial values based on the value from the JSON data in Moodle
    // database. This will have values undefined if creating a new one.
    if (json.dedication !== undefined) {
        node.one('input[name=dedication]').set('value', json.dedication);
    }

    // Add event handlers (first time only). You can do this any way you
    // like, but this pattern is used by the existing code.
    if (!M.availability_dedication.form.addedEvents) {
        M.availability_dedication.form.addedEvents = true;
        root = Y.one('.availability-field');
        root.delegate('change', function() {

            M.core_availability.form.update();
        }, '.availability_dedication input[name=dedication]');
    }

    return node;
};

M.availability_dedication.form.fillValue = function(value, node) {
    "use strict";

    value.dedication = node.one('input[name=dedication]').get('value');
};


M.availability_dedication.form.fillErrors = function(errors, node) {
    "use strict";
    var value = {};
    this.fillValue(value, node);
    // Check the password has been set.
    if (value.dedication === undefined || value.dedication === '' || (value.dedication.split("/")).length != 2) {
        errors.push('availability_dedication:validnumber');
    }
};


}, '@VERSION@', {"requires": ["base", "node", "event", "moodle-core_availability-form"]});
