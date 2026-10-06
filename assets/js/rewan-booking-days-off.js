(function ($) {
    'use strict';

    function getEmployeeId() {
        var $employee = $('#rb_days_off_employee');
        return parseInt($employee.val(), 10) || 0;
    }

    function getSelectedOptionColor() {
        var $opt = $('#rb_days_off_employee option:selected');
        var fromOpt = $opt.length ? $opt.attr('data-rb-color') : '';
        if (fromOpt) {
            return String(fromOpt);
        }
        var colors = (typeof RewanDaysOffL10n !== 'undefined' && RewanDaysOffL10n.employeeColors) || {};
        var id = String(getEmployeeId());
        return colors[id] ? String(colors[id]) : '';
    }

    function getSelectedEmployeeName() {
        var $opt = $('#rb_days_off_employee option:selected');
        if ($opt.length) {
            return $.trim($opt.text());
        }
        return '';
    }

    function updateEmployeeColorUi() {
        var hex = getSelectedOptionColor();
        var $sel = $('#rb_days_off_employee');
        if (hex) {
            $sel.css({
                borderLeftWidth: '4px',
                borderLeftStyle: 'solid',
                borderLeftColor: hex
            });
        } else {
            $sel.css({ borderLeftWidth: '', borderLeftStyle: '', borderLeftColor: '' });
        }
        var id = getEmployeeId();
        $('.rb-abs-days-off-legend-item').removeClass('is-selected');
        $('.rb-abs-days-off-legend-item[data-employee-id="' + id + '"]').addClass('is-selected');
    }

    function parseFirstVisibleMonthDate($container) {
        var firstId = $container.find('.bookly-js-holidays .jCalMo:first .day[id*="d_"]').first().attr('id') || '';
        var m = /d_(\d{1,2})_(\d{1,2})_(\d{4})/.exec(firstId);
        if (!m) {
            return null;
        }
        return new Date(parseInt(m[3], 10), parseInt(m[1], 10) - 1, 1);
    }

    function renderYearSeparators($container) {
        var $cal = $container.find('.bookly-js-holidays');
        var $months = $cal.find('.jCalMo');
        if ($months.length === 0) {
            return;
        }

        $cal.find('.rb-jcal-year-separator').remove();
        var start = parseFirstVisibleMonthDate($container);
        if (!start) {
            return;
        }

        $months.each(function (idx) {
            if (idx === 0) {
                return;
            }
            var d = new Date(start.getFullYear(), start.getMonth() + idx, 1);
            if (d.getMonth() !== 0) {
                return;
            }
            $('<div class="rb-jcal-year-separator"><span>' + d.getFullYear() + '</span></div>').insertBefore($(this));
        });
    }

    function initCalendar(events) {
        var $container = $('#bookly-holidays-container');
        if ($container.length === 0 || typeof $.fn.jCal !== 'function') {
            return;
        }

        $('.bookly-js-holidays', $container).jCal({
            day: new Date(new Date().getFullYear(), new Date().getMonth(), 1),
            days: 1,
            showMonths: 12,
            scrollSpeed: 350,
            action: 'rewan_booking_update_holidays',
            csrf_token: RewanDaysOffL10n.csrfToken,
            staff_id: getEmployeeId(),
            events: events || {},
            dayOffset: parseInt(RewanDaysOffL10n.firstDay, 10) || 1,
            dow: RewanDaysOffL10n.days || [],
            ml: RewanDaysOffL10n.months || [],
            we_are_not_working: RewanDaysOffL10n.weAreNotWorking || '',
            close: RewanDaysOffL10n.close || 'Fertig',
            popover_title: RewanDaysOffL10n.popoverTitle || '',
            popover_intro: RewanDaysOffL10n.popoverIntro || '',
            section_whole_title: RewanDaysOffL10n.sectionWholeTitle || '',
            section_whole_hint: RewanDaysOffL10n.sectionWholeHint || '',
            btn_whole_day: RewanDaysOffL10n.btnWholeDayFree || '',
            btn_save_times: RewanDaysOffL10n.btnSaveTimes || '',
            btn_not_free: RewanDaysOffL10n.btnNotFree || '',
            label_from: RewanDaysOffL10n.labelFromTime || '',
            label_to: RewanDaysOffL10n.labelToTime || '',
            section_times: RewanDaysOffL10n.sectionTimes || '',
            times_hint: RewanDaysOffL10n.timesHint || '',
            alert_times: RewanDaysOffL10n.alertTimes || '',
            ajax_error: RewanDaysOffL10n.ajaxError || '',
            employee_name: getSelectedEmployeeName(),
            employee_color: getSelectedOptionColor()
        });
        renderYearSeparators($container);

        $('.bookly-js-jCalBtn', $container)
            .off('click.rewanDaysOff')
            .on('click.rewanDaysOff', function (e) {
                e.preventDefault();
                var trigger = $(this).data('trigger');
                $('.bookly-js-holidays', $container).find($(trigger)).trigger('click');
                setTimeout(function () {
                    renderYearSeparators($container);
                }, 30);
            });
    }

    $(function () {
        if (typeof RewanDaysOffL10n === 'undefined') {
            return;
        }

        if (RewanDaysOffL10n.standalone) {
            $('.bookly-js-jCalBtn[data-trigger]').each(function () {
                var $btn = $(this);
                var $i = $btn.find('i.fas');
                if (!$i.length) {
                    return;
                }
                var trig = String($btn.data('trigger') || '');
                var isLeft = trig.indexOf('left') !== -1;
                $i.replaceWith(
                    $('<span class="rb-jcal-nav-chevron" aria-hidden="true"></span>').text(isLeft ? '\u2039' : '\u203a')
                );
            });
        }

        var eventsMap = RewanDaysOffL10n.eventsMap || {};
        initCalendar(eventsMap[getEmployeeId()] || []);
        updateEmployeeColorUi();

        $('#rb_days_off_employee').on('change', function () {
            updateEmployeeColorUi();
            initCalendar(eventsMap[getEmployeeId()] || []);
        });

        $(document).on('click', '.rb-abs-days-off-legend-item', function (e) {
            e.preventDefault();
            var raw = $(this).data('employee-id');
            var id = parseInt(raw, 10) || 0;
            if (id <= 0) {
                return;
            }
            $('#rb_days_off_employee').val(String(id)).trigger('change');
        });

        // Keep local map in sync with update endpoint responses.
        $(document).ajaxComplete(function (_e, xhr, settings) {
            if (!settings || !settings.data || typeof settings.data !== 'string') {
                return;
            }
            if (settings.data.indexOf('action=rewan_booking_update_holidays') === -1) {
                return;
            }
            if (!xhr || !xhr.responseText) {
                return;
            }
            try {
                var parsed = JSON.parse(xhr.responseText);
                if (parsed && parsed.success && parsed.data) {
                    var employee = getEmployeeId();
                    if (employee > 0) {
                        eventsMap[employee] = parsed.data;
                    }
                }
            } catch (_err) {
                // ignore malformed responses
            }
        });
    });
})(jQuery);
