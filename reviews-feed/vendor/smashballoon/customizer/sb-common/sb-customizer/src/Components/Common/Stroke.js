import { useContext, useState } from "react";
import { __ } from '@wordpress/i18n'
import Input from './Input'
import Button from './Button'
import Checkbox from './Checkbox'
import ColorPicker from './ColorPicker'
import FeedEditorContext from "../Context/FeedEditorContext";

const Stroke = ( props ) => {
    const {
        editorControl
    } = useContext( FeedEditorContext );

    const   [ initialValue, setInitialValue ] = useState( props?.value );

    const   [ strokePopupActive, setStrokePopupActive ] = useState( false ),
            defaultValues = {
                enabled     : false,
                thickness   : 0,
                color       : '#eee',
                ...props?.value
            };
    const [ strokeValues,  setStrokeValues ] = useState( defaultValues );

    const changeStrokeValue = ( el, type, isValue = false ) => {
        const newStrokeValues = {
            ...strokeValues,
            [ type ] : !isValue ? el.currentTarget.value : el
        };
        setStrokeValues( newStrokeValues );
       props.onChange( newStrokeValues );
    }
    return (
        <div className='sb-popupcontrol-ctn sb-stroke-ctn sb-fs' data-active={ strokePopupActive && editorControl.tempControlPopup === props.customId}>
            <div className='sb-popupcontrol-checkbox'>
                <Checkbox
                    value={ strokeValues.enabled }
                    enabled={ true }
                    onChange = { () =>
                        changeStrokeValue( !strokeValues.enabled, 'enabled', true )
                    }
                />
            </div>
            <div className='sb-popupcontrol-content'>
                <span className='sb-popupcontrol-label sb-bold sb-text-tiny'>{ props.label }</span>
                <span className='sb-text-tiny sb-popupcontrol-values-list'>
                    <span className='sb-popupcontrol-value'>{ strokeValues.thickness }px</span>
                    <span className='sb-popupcontrol-color'>
                        <span className='sb-popupcontrol-color-icon' style={ { background: strokeValues.color } }></span>
                        <span className='sb-popupcontrol-value'>{ strokeValues.color }</span>
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
                        setStrokePopupActive( !strokePopupActive )
                        changeStrokeValue( true, 'enabled', true )
                    }}
                />
                <div className='sb-popupcontrol-popup sb-tr-1'>
                    <div className='sb-popupcontrol-header sb-fs'>
                        <strong className='sb-text-small'>{ __( 'Edit Stroke', 'sb-customizer' ) }</strong>
                        <div
                            className='sb-cls-ctn sb-popupcontrol-popup-cls'
                            onClick={ () => {
                                editorControl.setTempControlPopup( null )
                                setStrokePopupActive( false )
                            }}
                        >
                        </div>
                    </div>
                    <div className='sb-popupcontrol-inputs sb-fs'>
                        <div className="sb-popupcontrol-1row">
                            <div className='sb-popupcontrol-inp-color sb-fs'>
                                <span>{ __( 'Color', 'sb-customizer' ) }</span>
                                <div>
                                    <ColorPicker
                                        disabled={props?.disabled === true}
                                        value={ strokeValues.color }
                                        onChange={ ( event ) => {
                                            changeStrokeValue( event, 'color', true )
                                        } }
                                    />
                                </div>
                            </div>
                            <div className='sb-popupcontrol-inp-item'>
                                    <span>{ __( 'Thickness', 'sb-customizer' ) }</span>
                                    <Input
                                        type='number'
                                        size='small'
                                        placeholder='4px'
                                        trailingText='px'
                                        disablebg='true'
                                        value={ strokeValues.thickness }
                                        onChange = { ( event ) =>
                                            changeStrokeValue( event, 'thickness' )
                                        }
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
                                setStrokeValues( initialValue )
                                props.onChange( initialValue );
                            } }
                        />
                    </div>

                </div>
            </div>
        </div>
    )
}

export default Stroke;