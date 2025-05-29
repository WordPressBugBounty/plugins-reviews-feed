import { useContext, useMemo, useState } from "react";
import { __ } from '@wordpress/i18n'
import Input from './Input'
import Button from './Button'
import Checkbox from './Checkbox'
import ColorPicker from './ColorPicker'
import FeedEditorContext from "../Context/FeedEditorContext";

const BoxShadow = ( props ) => {
    const {
        editorControl
    } = useContext( FeedEditorContext );

    const   [ initialValue, setInitialValue ] = useState( props?.value );

    const   [ boxShadowPopupActive, setBoxShadowPopupActive ] = useState( false ),
            defaultValues = {
                enabled : false,
                x       : '',
                y       : '',
                blur    : '',
                spread  : '',
                color   : '',
                ...props?.value
            };



    const [ boxShadowValues,  setBoxShadowValues ] = useState( defaultValues );

    const changeBoxShadowValue = ( el, type, isValue = false ) => {
        const newShadowValues = {
            ...boxShadowValues,
            [ type ] : !isValue ? el.currentTarget.value : el
        };
        setBoxShadowValues( newShadowValues );
        props.onChange( newShadowValues );
    }

    return (
        <div className='sb-popupcontrol-ctn sb-boxshadow-ctn sb-fs' data-active={ boxShadowPopupActive && editorControl.tempControlPopup === props.customId }>
            <div className='sb-popupcontrol-checkbox'>
                <Checkbox
                    value={ boxShadowValues.enabled }
                    enabled={ true }
                    onChange = { () =>
                        changeBoxShadowValue( !boxShadowValues.enabled, 'enabled', true )
                    }
                />
            </div>
            <div className='sb-popupcontrol-content'>
                <span className='sb-popupcontrol-label sb-bold sb-text-tiny'>{ props.label }</span>
                <span className='sb-text-tiny sb-popupcontrol-values-list'>
                    <span className='sb-popupcontrol-unit'>X</span>
                    <span className='sb-popupcontrol-value'>{ boxShadowValues.x }</span>
                    <span className='sb-popupcontrol-unit'>Y</span>
                    <span className='sb-popupcontrol-value'>{ boxShadowValues.y }</span>
                    <span className='sb-popupcontrol-unit'>B</span>
                    <span className='sb-popupcontrol-value'>{ boxShadowValues.blur }</span>
                    <span className='sb-popupcontrol-unit'>S</span>
                    <span className='sb-popupcontrol-value'>{ boxShadowValues.spread }</span>
                    <span className='sb-popupcontrol-color'>
                        <span className='sb-popupcontrol-color-icon' style={ { background: boxShadowValues.color } }></span>
                        <span className='sb-popupcontrol-value'>{ boxShadowValues.color }</span>
                    </span>
                </span>
            </div>
            <div className='sb-popupcontrol-chooser'>
                <Button
                    size='small'
                    icon='pen'
                    type='secondary'
                    boxshadow='false'
                    onClick={ () => {
                        editorControl.setTempControlPopup( props.customId )
                        setBoxShadowPopupActive( !boxShadowPopupActive )
                        changeBoxShadowValue( true, 'enabled', true )
                    }}
                />
                <div className='sb-popupcontrol-popup sb-tr-1'>
                    <div className='sb-popupcontrol-header sb-fs'>
                        <strong className='sb-text-small'>{ __( 'Edit Box Shadow', 'sb-customizer' ) }</strong>
                        <div
                            className='sb-cls-ctn sb-popupcontrol-popup-cls'
                            onClick={ () => {
                                editorControl.setTempControlPopup( null )
                                setBoxShadowPopupActive( false )
                            }}
                        >
                        </div>
                    </div>
                    <div className='sb-popupcontrol-inputs sb-fs'>
                        <div>
                            <div className='sb-popupcontrol-inp-item'>
                                <span>X</span>
                                <Input
                                    size='small'
                                    placeholder='4px'
                                    trailingText='px'
                                    disablebg='true'
                                    value={ boxShadowValues.x }
                                    onChange = { ( event ) =>
                                        changeBoxShadowValue( event, 'x' )
                                    }
                                />
                            </div>
                            <div className='sb-popupcontrol-inp-item'>
                                <span>Y</span>
                                <Input
                                    size='small'
                                    placeholder='4px'
                                    trailingText='px'
                                    disablebg='true'
                                    value={ boxShadowValues.y }
                                    onChange = { ( event ) =>
                                        changeBoxShadowValue( event, 'y' )
                                    }
                                />
                            </div>
                        </div>
                        <div>
                            <div className='sb-popupcontrol-inp-item'>
                                <span>{ __( 'Blur', 'sb-customizer' ) }</span>
                                <Input
                                    size='small'
                                    placeholder='4px..'
                                    trailingText='px'
                                    disablebg='true'
                                    value={ boxShadowValues.blur }
                                    onChange = { ( event ) =>
                                        changeBoxShadowValue( event, 'blur' )
                                    }
                                />
                            </div>
                            <div className='sb-popupcontrol-inp-item'>
                                <span>{ __( 'Spread', 'sb-customizer' ) }</span>
                                <Input
                                    size='small'
                                    placeholder='4px..'
                                    trailingText='px'
                                    disablebg='true'
                                    value={ boxShadowValues.spread }
                                    onChange = { ( event ) =>
                                        changeBoxShadowValue( event, 'spread' )
                                    }
                                />
                            </div>
                        </div>
                        <div className='sb-popupcontrol-inp-color sb-fs'>
                            <span>{ __( 'Color', 'sb-customizer' ) }</span>
                            <div>
                                <ColorPicker
                                    disabled={props?.disabled === true}
                                    value={ boxShadowValues.color }
                                    onChange={ ( event ) => {
                                        changeBoxShadowValue( event, 'color', true )
                                    } }
                                />
                            </div>
                        </div>
                    </div>
                    <div className='sb-popupcontrol-button sb-fs'>
                        <Button
                            icon='reset'
                            size='small'
                            type='secondary'
                            full-width='true'
                            boxshadow='false'
                            text={ __( 'Reset', 'sb-customizer' ) }
                            onClick={ () => {
                                setBoxShadowValues(initialValue)
                                props.onChange( initialValue );
                            } }
                        />
                    </div>

                </div>
            </div>
        </div>
    )

}

export default BoxShadow;