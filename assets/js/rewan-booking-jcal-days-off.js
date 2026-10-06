/*
 * jCal (Rewan Days Off fork) — basiert auf jCal 0.3.7 (MIT, Jim Palmer).
 * Popover: klare Buttons, Uhrzeiten, kein „Jährlich wiederholen“.
 */
(function ($) {
    $.fn.jCal = function (opt) {
        $.jCal(this, opt);
        return this;
    };

    var events = {},
        hoverSelect = 'stop',
        intervalStart = null;

    $.jCal = function (target, opt) {
        opt = $.extend(
            {
                day: new Date(),
                events: {},
                action: '',
                staff_id: false,
                days: 1,
                showMonths: 1,
                monthSelect: false,
                dCheck: function () {
                    return 'day';
                },
                callback: function () {
                    return true;
                },
                drawBack: function () {
                    return true;
                },
                selectedBG: 'rgb(0, 143, 214)',
                defaultBG: 'rgb(255, 255, 255)',
                dayOffset: 0,
                scrollSpeed: 150,
                forceWeek: false,
                ms: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                _target: target
            },
            opt
        );
        opt.day = new Date(opt.day.getFullYear(), opt.day.getMonth(), 1);
        if (!$(opt._target).data('days')) {
            $(opt._target).data('days', opt.days);
        }
        var $target = $(target);
        $target.stop().empty();
        events = opt.events;
        for (var sm = 0; sm < opt.showMonths; sm++) {
            $target.append('<div class="jCalMo"></div>');
        }
        opt.cID = 'c' + $('.jCalMo').length;
        $('.jCalMo', $target).each(function (ind) {
            drawCalControl(
                $(this),
                $.extend({}, opt, {
                    ind: ind,
                    day: new Date(new Date(opt.day.getTime()).setMonth(new Date(opt.day.getTime()).getMonth() + ind))
                })
            );
            drawCal(
                $(this),
                $.extend({}, opt, {
                    ind: ind,
                    day: new Date(new Date(opt.day.getTime()).setMonth(new Date(opt.day.getTime()).getMonth() + ind))
                })
            );
            $(this).attr('data-index', ind);
        });
    };

    function drawCalControl($target, opt) {
        $target.append(
            '<div class="jCal">' +
                (opt.ind == 0 ? '<div class="left"></div>' : '') +
                '<div class="month">' +
                '<span class="monthName">' +
                opt.ml[opt.day.getMonth()] +
                '</span>' +
                '</div>' +
                (opt.ind == opt.showMonths - 1 ? '<div class="right"></div>' : '') +
                '</div>'
        );

        if (opt.ind === 0) {
            $('.jcal_year').text(opt.day.getFullYear());
        }

        $target.find('.jCal .left').bind('click', $.extend({}, opt), function (e) {
            if ($('.jCalMask', e.data._target).length > 0) {
                return false;
            }
            $(e.data._target).stop();
            var mD = { w: 0, h: 0 };
            $('.jCalMo', e.data._target).each(function () {
                mD.w += $(this).width() + parseInt($(this).css('padding-left'), 10) + parseInt($(this).css('padding-right'), 10);
                var cH = $(this).height() + parseInt($(this).css('padding-top'), 10) + parseInt($(this).css('padding-bottom'), 10);
                mD.h = cH > mD.h ? cH : mD.h;
            });
            var right = null;
            for (var i = 11; i >= 0; i--) {
                $(e.data._target).prepend('<div class="jCalMo" data-index="' + i + '"></div>');
                e.data.day = new Date(
                    $('div[id*=' + e.data.cID + 'd_]:first', e.data._target)
                        .attr('id')
                        .replace(e.data.cID + 'd_', '')
                        .replace(/_/g, '/')
                );
                e.data.day.setDate(1);
                e.data.day.setMonth(e.data.day.getMonth() - 1);
                drawCalControl($('.jCalMo:first', e.data._target), e.data);
                drawCal($('.jCalMo:first', e.data._target), e.data);
                right = $('.right', e.data._target).clone(true);
            }
            for (var j = 0; j < 12; j++) {
                $('.jCalMo:last').remove();
            }
            right.appendTo($('.jCalMo:eq(1) .jCal', e.data._target));
        });

        $target.find('.jCal .right').bind('click', $.extend({}, opt), function (e) {
            if ($('.jCalMask', e.data._target).length > 0) {
                return false;
            }
            $(e.data._target).stop();
            var mD = { w: 0, h: 0 };
            $('.jCalMo', e.data._target).each(function () {
                mD.w += $(this).width() + parseInt($(this).css('padding-left'), 10) + parseInt($(this).css('padding-right'), 10);
                var cH = $(this).height() + parseInt($(this).css('padding-top'), 10) + parseInt($(this).css('padding-bottom'), 10);
                mD.h = cH > mD.h ? cH : mD.h;
            });
            var left = false;
            for (var i = 0; i < 12; i++) {
                $(e.data._target).append('<div class="jCalMo" data-index="' + i + '"></div>');
                e.data.day = new Date(
                    $('div[id^=' + e.data.cID + 'd_]:last', e.data._target)
                        .attr('id')
                        .replace(e.data.cID + 'd_', '')
                        .replace(/_/g, '/')
                );
                e.data.day.setDate(1);
                e.data.day.setMonth(e.data.day.getMonth() + 1);
                drawCalControl($('.jCalMo:last', e.data._target), e.data);
                drawCal($('.jCalMo:last', e.data._target), e.data);
                left = $('.left', e.data._target).clone(true);
            }
            for (var j = 0; j < 12; j++) {
                $('.jCalMo:first').remove();
            }
            left.prependTo($('.jCalMo:eq(1) .jCal', e.data._target));
        });
    }

    function drawCal($target, opt) {
        for (var ds = opt.dayOffset; ds < 7; ds++) {
            $target.append('<div class="dow">' + opt.dow[ds] + '</div>');
        }
        for (var ds2 = 0; ds2 < opt.dayOffset; ds2++) {
            $target.append('<div class="dow">' + opt.dow[ds2] + '</div>');
        }

        var fd = new Date(new Date(opt.day.getTime()).setDate(1));
        var ld = new Date(new Date(new Date(fd.getTime()).setMonth(fd.getMonth() + 1)).setDate(0));
        var ldlm = new Date(new Date(fd.getTime()).setDate(0));
        var fdnm = new Date(new Date(new Date(fd.getTime()).setMonth(fd.getMonth() + 1)).setDate(1));

        if (fd.getDay() != opt.dayOffset) {
            var diff = fd.getDay() < opt.dayOffset ? fd.getDay() + 7 - opt.dayOffset : Math.abs(fd.getDay() - opt.dayOffset);
            for (var pd = ldlm.getDate() - diff + 1; pd <= ldlm.getDate(); pd++) {
                $target.append('<div id="' + opt.cID + 'd' + pd + '" class="pday">' + pd + '</div>');
            }
        }

        for (var d = 1; d <= ld.getDate(); d++) {
            $target.append(
                '<div id="' +
                    opt.cID +
                    'd_' +
                    (fd.getMonth() + 1) +
                    '_' +
                    d +
                    '_' +
                    fd.getFullYear() +
                    '" class="day">' +
                    d +
                    '</div>'
            );
        }

        if ((opt.dayOffset && ld.getDay() != opt.dayOffset - 1) || (!opt.dayOffset && ld.getDay() != 6)) {
            var diff2 = fdnm.getDay() >= opt.dayOffset ? 6 - fdnm.getDay() + opt.dayOffset : 6 - (fdnm.getDay() - opt.dayOffset + 7);
            for (var nd = 1; nd <= diff2 + 1; nd++) {
                $target.append('<div id="' + opt.cID + 'd' + nd + '" class="pday">' + nd + '</div>');
            }
        }

        $target.find('div[id^=' + opt.cID + 'd]:first, div[id^=' + opt.cID + 'd]:nth-child(7n+2)').before('<div style="clear:both;"></div>');
        $target.find('div[id^=' + opt.cID + 'd_]:not(.invday)').bind('mouseover mouseout click', $.extend({}, opt), function (e) {
            if ($('.jCalMask', e.data._target).length > 0) {
                return false;
            }
            var osDate = new Date($(this).attr('id').replace(/c[0-9]{1,}d_([0-9]{1,2})_([0-9]{1,2})_([0-9]{4})/, '$1/$2/$3'));
            if (e.data.forceWeek) {
                osDate.setDate(osDate.getDate() + (e.data.dayOffset - osDate.getDay()));
            }
            var sDate = new Date(osDate.getTime());

            if (e.type == 'click') {
                $('div[id*=d_]', e.data._target).stop().removeClass('overDay');
                if (intervalStart !== null) {
                    hoverSelect = 'stop';
                    drawPopup($target, opt, this, [intervalStart, new Date(sDate)]);
                    intervalStart = null;
                } else {
                    $('.day', $target.closest('.jCal-wrap')).removeClass('selectedDay');
                    intervalStart = new Date(sDate);
                    hoverSelect = 'start';
                }
            } else if (hoverSelect == 'start') {
                reSelectDates($target, intervalStart, sDate);
            }

            for (var di = 0, ds = $(e.data._target).data('days'); di < ds; di++) {
                var currDay = $(e.data._target).find(
                    '#' + e.data.cID + 'd_' + (sDate.getMonth() + 1) + '_' + sDate.getDate() + '_' + sDate.getFullYear()
                );
                if (currDay.length == 0 || $(currDay).hasClass('invday')) {
                    break;
                }
                if (e.type == 'mouseover') {
                    $(currDay).addClass('overDay');
                } else if (e.type == 'mouseout') {
                    $(currDay).stop().removeClass('overDay');
                } else if (e.type == 'click') {
                    $(currDay).stop().addClass('selectedDay');
                }
                sDate.setDate(sDate.getDate() + 1);
            }
            if (e.type == 'click') {
                e.data.day = osDate;
                if (e.data.callback(osDate, di, this)) {
                    $(e.data._target).data('day', e.data.day).data('days', di);
                }
            }
        });

        if (events) {
            drawEvents($target, fd.getMonth() + 1, opt);
        }
    }

    function reSelectDates($target, startDay, endDay) {
        if (startDay) {
            var $container = $target.closest('.jCal-wrap'),
                start = startDay;
            if (startDay.getTime() > endDay.getTime()) {
                start = endDay;
                endDay = new Date(startDay);
            }
            $('.day', $container).removeClass('selectedDay');
            for (var d = new Date(start); d <= endDay; d.setDate(d.getDate() + 1)) {
                var dF = $('div[id*=d_' + (d.getMonth() + 1) + '_' + d.getDate() + '_' + d.getFullYear() + ']', $container);
                dF.stop().addClass('selectedDay');
            }
        }
    }

    function drawEvents($target, month, opt) {
        $('.holidayDay', $target).removeClass('holidayDay').removeClass('repeatDay').removeAttr('title');
        for (var i in events) {
            if (events.hasOwnProperty(i)) {
                if (events[i].m == month) {
                    var isRepeat = !events[i].hasOwnProperty('y');
                    var tooltip = opt.we_are_not_working || '';
                    $target
                        .find(getEventSelector(events[i]))
                        .addClass('holidayDay')
                        .addClass(isRepeat ? 'repeatDay' : '')
                        .attr('title', tooltip);
                }
            }
        }
    }

    function getEventSelector(event) {
        return (
            'div[id^=c12d_' +
            event.m +
            '_' +
            event.d +
            '_' +
            (event.hasOwnProperty('y') ? event.y + ']' : ']')
        );
    }

    function normalizeRange(range) {
        if (range[0].getTime() > range[1].getTime()) {
            var start = range[1];
            range[1] = new Date(range[0]);
            range[0] = start;
        }
        return range;
    }

    function redrawVisibleMonths($container, opt) {
        $('.jCalMo', $container).each(function () {
            var $mo = $(this);
            var label = $.trim($mo.find('.monthName').first().text());
            var monthIndex = opt.ml.indexOf(label) + 1;
            if (monthIndex > 0) {
                drawEvents($mo, monthIndex, opt);
            }
        });
    }

    function postHoliday($target, opt, range, payload, $btn, $div) {
        normalizeRange(range);
        var $container = $target.closest('.jCal-wrap');
        var options = $.extend(
            {
                action: opt.action,
                csrf_token: opt.csrf_token,
                holiday: 'false',
                repeat: 'false',
                all_day: 'true',
                start_time: '09:00',
                end_time: '18:00',
                range: [
                    range[0].getFullYear() + '-' + (range[0].getMonth() + 1) + '-' + range[0].getDate(),
                    range[1].getFullYear() + '-' + (range[1].getMonth() + 1) + '-' + range[1].getDate()
                ]
            },
            payload
        );
        if (opt.staff_id) {
            options.staff_id = opt.staff_id;
        }

        var $busy = $btn ? $($btn) : $();
        if ($busy.length) {
            $busy.prop('disabled', true).addClass('bookly-checkbox-loading');
        }

        $.post(ajaxurl, options, function (response) {
            if (response && response.success) {
                events = response.data;
                redrawVisibleMonths($container, opt);
                $('.day', $container).removeClass('selectedDay');
                if ($div && $div.booklyPopover) {
                    $div.booklyPopover('hide');
                }
                return;
            }
            var msg =
                (response && response.data && (response.data.message || response.data)) ||
                (opt.ajax_error && String(opt.ajax_error)) ||
                '';
            if (typeof msg === 'object') {
                msg = JSON.stringify(msg);
            }
            if (msg) {
                window.alert(String(msg));
            }
        }, 'json')
            .always(function () {
                if ($busy.length) {
                    $busy.prop('disabled', false).removeClass('bookly-checkbox-loading');
                }
            })
            .fail(function () {
                window.alert((opt.ajax_error && String(opt.ajax_error)) || 'Anfrage fehlgeschlagen.');
            });
    }

    function drawPopup($target, opt, div, range) {
        $('.bookly-popover').booklyPopover('hide');
        var $div = $(div);
        var $first = $target.find(
            'div[id*=d_' +
                (range[0].getMonth() + 1) +
                '_' +
                range[0].getDate() +
                '_' +
                range[0].getFullYear() +
                ']'
        );
        var hasHoliday = $first.hasClass('holidayDay');

        var title = opt.popover_title || 'Frei eintragen';
        var intro = opt.popover_intro || '';
        var secWhole = opt.section_whole_title || '';
        var hintWhole = opt.section_whole_hint || '';
        var btnWhole = opt.btn_whole_day || 'Ganze Markierung frei';
        var btnTimes = opt.btn_save_times || 'Speichern';
        var btnClear = opt.btn_not_free || 'Frei aufheben';
        var btnClose = opt.close || 'Fertig';
        var labFrom = opt.label_from || 'Von';
        var labTo = opt.label_to || 'Bis';
        var secTimes = opt.section_times || '';
        var hintTimes = opt.times_hint || '';
        var employeeName = opt.employee_name || '';
        var employeeColor = opt.employee_color || '#2271b1';

        var $popup = $(
            '<div class="rb-jcal-pop" role="document">' +
                '<div class="rb-jcal-pop__hero">' +
                '<h3 class="rb-jcal-pop__title">' +
                title +
                '</h3>' +
                (intro ? '<p class="rb-jcal-pop__intro">' + intro + '</p>' : '') +
                (employeeName
                    ? '<p class="rb-jcal-pop__employee"><span class="rb-jcal-pop__employee-dot" style="background:' +
                      employeeColor +
                      '"></span><span class="rb-jcal-pop__employee-label">Mitarbeiter:</span> ' +
                      employeeName +
                      '</p>'
                    : '') +
                '</div>' +
                '<section class="rb-jcal-pop__section">' +
                (secWhole ? '<h4 class="rb-jcal-pop__section-title">' + secWhole + '</h4>' : '') +
                (hintWhole ? '<p class="rb-jcal-pop__section-hint">' + hintWhole + '</p>' : '') +
                '<button type="button" class="btn btn-primary btn-block rb-jcal-btn-whole">' +
                btnWhole +
                '</button>' +
                '</section>' +
                '<div class="rb-jcal-pop__divider" aria-hidden="true"></div>' +
                '<section class="rb-jcal-pop__section rb-jcal-pop__section--soft">' +
                (secTimes ? '<h4 class="rb-jcal-pop__section-title">' + secTimes + '</h4>' : '') +
                (hintTimes ? '<p class="rb-jcal-pop__section-hint">' + hintTimes + '</p>' : '') +
                '<div class="rb-jcal-pop__times form-row">' +
                '<div class="col">' +
                '<label class="rb-jcal-pop__lab">' +
                labFrom +
                '</label>' +
                '<input type="time" class="form-control rb-jcal-time-start" value="09:00" />' +
                '</div>' +
                '<div class="col">' +
                '<label class="rb-jcal-pop__lab">' +
                labTo +
                '</label>' +
                '<input type="time" class="form-control rb-jcal-time-end" value="18:00" />' +
                '</div>' +
                '</div>' +
                '<button type="button" class="btn btn-primary btn-block rb-jcal-btn-times">' +
                btnTimes +
                '</button>' +
                '</section>' +
                '<div class="rb-jcal-pop__footer">' +
                '<button type="button" class="btn btn-link rb-jcal-btn-clear">' +
                btnClear +
                '</button>' +
                '<button type="button" class="btn btn-default rb-jcal-btn-close">' +
                btnClose +
                '</button>' +
                '</div>' +
                '</div>'
        );

        $popup.on('click', function (e) {
            e.stopPropagation();
        });

        $popup.find('.rb-jcal-btn-close').on('click', function (e) {
            e.preventDefault();
            $div.booklyPopover('hide');
        });

        $popup.find('.rb-jcal-btn-whole').on('click', function (e) {
            e.preventDefault();
            postHoliday(
                $target,
                opt,
                range,
                { holiday: 'true', all_day: 'true' },
                this,
                $div
            );
        });

        $popup.find('.rb-jcal-btn-times').on('click', function (e) {
            e.preventDefault();
            var t1 = $.trim($popup.find('.rb-jcal-time-start').val() || '');
            var t2 = $.trim($popup.find('.rb-jcal-time-end').val() || '');
            if (!t1 || !t2) {
                window.alert(opt.alert_times || 'Bitte Von- und Bis-Uhrzeit angeben.');
                return;
            }
            postHoliday(
                $target,
                opt,
                range,
                { holiday: 'true', all_day: 'false', start_time: t1, end_time: t2 },
                this,
                $div
            );
        });

        $popup.find('.rb-jcal-btn-clear').on('click', function (e) {
            e.preventDefault();
            if (hasHoliday) {
                postHoliday($target, opt, range, { holiday: 'false', all_day: 'true' }, this, $div);
            } else {
                $div.booklyPopover('hide');
            }
        });

        $div.booklyPopover({
            html: true,
            placement: 'bottom',
            container: $('.bookly-js-holidays-nav'),
            title: '',
            template:
                '<div class="bookly-popover bookly-jcal" role="dialog" aria-modal="true"><div class="arrow"></div><h3 class="popover-header"></h3><div class="popover-body"></div></div>',
            content: function () {
                return $popup;
            },
            trigger: 'focus'
        }).booklyPopover('show');
    }

    $('body').on('click', function (e) {
        var $t = $(e.target);
        if (!$t.hasClass('day') && $t.closest('.bookly-popover.bookly-jcal').length == 0) {
            $('.bookly-popover.bookly-jcal').booklyPopover('hide');
        }
    });
})(jQuery);
