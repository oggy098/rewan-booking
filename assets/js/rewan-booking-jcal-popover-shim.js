/**
 * Minimaler Ersatz für Booklys jQuery booklyPopover, falls Bookly nicht installiert ist.
 * Nur die von rewan-booking-jcal-days-off.js genutzte API: .booklyPopover(opts).booklyPopover('show') und .booklyPopover('hide').
 */
(function ($) {
    'use strict';

    var DATA = 'rewanJcalPopoverInst';

    function parseInst($el) {
        var d = $el.data(DATA);
        return d && typeof d === 'object' ? d : null;
    }

    function hidePopoverEl($pop) {
        if (!$pop || !$pop.length) {
            return;
        }
        var $tr = $pop.data('rbJcalTrigger');
        if ($tr && $tr.length) {
            var inst = parseInst($tr);
            if (inst) {
                inst.$pop = null;
                $tr.data(DATA, inst);
            }
        }
        $pop.remove();
    }

    if ($.fn.booklyPopover) {
        return;
    }

    $.fn.booklyPopover = function (arg) {
        if (typeof arg === 'string') {
            if (arg === 'hide') {
                return this.each(function () {
                    var $el = $(this);
                    if ($el.hasClass('bookly-popover')) {
                        hidePopoverEl($el);
                        return;
                    }
                    var inst = parseInst($el);
                    if (inst && inst.$pop && inst.$pop.length) {
                        hidePopoverEl(inst.$pop);
                    }
                });
            }
            if (arg === 'show') {
                return this.each(function () {
                    var $trigger = $(this);
                    var inst = parseInst($trigger);
                    if (!inst || !inst.opts) {
                        return;
                    }
                    var o = inst.opts;
                    $('.bookly-popover.bookly-jcal').each(function () {
                        hidePopoverEl($(this));
                    });

                    var $wrap = $(o.template);
                    var $body = $wrap.find('.popover-body');
                    var content =
                        typeof o.content === 'function' ? o.content.call($trigger[0]) : o.content;
                    $body.empty().append(content);

                    $wrap.addClass('bookly-show rb-jcal-popover-shim');
                    $wrap.data('rbJcalTrigger', $trigger);
                    $('body').append($wrap);

                    var r = $trigger[0].getBoundingClientRect();
                    var pad = 6;
                    var top = r.bottom + pad;
                    var left = Math.max(8, r.left);
                    $wrap.css({
                        position: 'fixed',
                        top: top + 'px',
                        left: left + 'px',
                        zIndex: 100050,
                        maxWidth: 'min(360px, calc(100vw - 24px))'
                    });
                    var w = $wrap.outerWidth();
                    if (left + w > window.innerWidth - 12) {
                        left = Math.max(8, window.innerWidth - w - 12);
                        $wrap.css('left', left + 'px');
                    }

                    inst.$pop = $wrap;
                    $trigger.data(DATA, inst);
                });
            }
            return this;
        }

        return this.each(function () {
            var $trigger = $(this);
            var prev = parseInst($trigger);
            if (prev && prev.$pop && prev.$pop.length) {
                hidePopoverEl(prev.$pop);
            }
            $trigger.data(DATA, { opts: arg, $pop: null });
        });
    };
})(jQuery);
