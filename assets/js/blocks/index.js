(function (wp) {
    'use strict';

    var registerBlockType  = wp.blocks.registerBlockType;
    var el                 = wp.element.createElement;
    var Fragment           = wp.element.Fragment;
    var InspectorControls  = wp.blockEditor.InspectorControls;
    var useBlockProps      = wp.blockEditor.useBlockProps;
    var PanelBody          = wp.components.PanelBody;
    var ToggleControl      = wp.components.ToggleControl;
    var SelectControl      = wp.components.SelectControl;
    var ServerSideRender   = wp.serverSideRender;
    var __                 = wp.i18n.__;

    registerBlockType('idiomattic-wp/language-switcher', {
        title:       __('Language Switcher', 'idiomattic-wp'),
        description: __('Display a language switcher for your multilingual site.', 'idiomattic-wp'),
        category:    'widgets',
        icon:        'translation',
        keywords:    ['language', 'multilingual', 'translate', 'idiomattic'],

        edit: function (props) {
            var attributes  = props.attributes;
            var setAttributes = props.setAttributes;
            var blockProps  = useBlockProps();

            return el(
                Fragment, null,

                // ── Inspector sidebar controls ──────────────────────────────
                el(InspectorControls, null,
                    el(PanelBody, { title: __('Display options', 'idiomattic-wp'), initialOpen: true },
                        el(SelectControl, {
                            label:    __('Style', 'idiomattic-wp'),
                            value:    attributes.style,
                            options:  [
                                { label: __('List', 'idiomattic-wp'),     value: 'list'     },
                                { label: __('Dropdown', 'idiomattic-wp'), value: 'dropdown' },
                            ],
                            onChange: function (val) { setAttributes({ style: val }); },
                        }),
                        el(ToggleControl, {
                            label:    __('Show flags', 'idiomattic-wp'),
                            checked:  attributes.showFlags,
                            onChange: function (val) { setAttributes({ showFlags: val }); },
                        }),
                        el(ToggleControl, {
                            label:    __('Show language names', 'idiomattic-wp'),
                            checked:  attributes.showNames,
                            onChange: function (val) { setAttributes({ showNames: val }); },
                        }),
                        el(ToggleControl, {
                            label:    __('Show native names', 'idiomattic-wp'),
                            checked:  attributes.showNativeNames,
                            onChange: function (val) { setAttributes({ showNativeNames: val }); },
                        }),
                        el(ToggleControl, {
                            label:    __('Hide current language', 'idiomattic-wp'),
                            checked:  attributes.hideCurrent,
                            onChange: function (val) { setAttributes({ hideCurrent: val }); },
                        })
                    )
                ),

                // ── Editor canvas: live server-side preview ─────────────────
                el('div', blockProps,
                    el(ServerSideRender, {
                        block:      'idiomattic-wp/language-switcher',
                        attributes: attributes,
                    })
                )
            );
        },

        save: function () {
            // Fully server-side rendered — save() must return null.
            return null;
        },
    });

}(window.wp));
