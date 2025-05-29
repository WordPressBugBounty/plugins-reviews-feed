import { useContext, useState } from "react";
import { __ } from '@wordpress/i18n'
import Input from './Input'
import Button from './Button'
import Checkbox from './Checkbox'
import FeedEditorContext from "../Context/FeedEditorContext";

const BorderRadius = ( props ) => {
    const {
        editorControl
    } = useContext( FeedEditorContext );

    const   [ initialValue, setInitialValue ] = useState( props?.value );

    const   [ borderRadiusPopupActive, setBorderRadiusPopupActive ] = useState( false ),
            defaultValues = {
                enabled : false,
                radius   : 0,
                ...props?.value
            };
    const [ borderRadiusValues,  setBorderRadiusValues ] = useState( defaultValues );

    const changeBorderRadiusValue = ( el, type, isValue = false ) => {
        const newBorderRadiusValues = {
            ...borderRadiusValues,
            [ type ] : !isValue ? el.currentTarget.value : el
        };
        setBorderRadiusValues( newBorderRadiusValues );
       props.onChange( newBorderRadiusValues );
    }
    return (
        <div className='sb-popupcontrol-ctn sb-borderradius-ctn sb-fs' data-active={ borderRadiusPopupActive && editorControl.tempControlPopup === props.customId }>
            <div className='sb-popupcontrol-checkbox'>
                <Checkbox
                    value={ borderRadiusValues.enabled }
                    enabled={ true }
                    onChange = { () =>
                        changeBorderRadiusValue( !borderRadiusValues.enabled, 'enabled', true )
                    }
                />
            </div>
            <div className='sb-popupcontrol-content'>
                <span className='sb-popupcontrol-label sb-bold sb-text-tiny'>{ props.label }</span>
                <span className='sb-text-tiny sb-popupcontrol-values-list'>
                    <span className='sb-popupcontrol-value'>{ borderRadiusValues.radius }px</span>
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
                        setBorderRadiusPopupActive( !borderRadiusPopupActive )
                        changeBorderRadiusValue( true, 'enabled', true )
                    }}
                />
                <div className='sb-popupcontrol-popup sb-tr-1'>
                    <div className='sb-popupcontrol-header sb-fs'>
                        <strong className='sb-text-small'>{ __( 'Edit Corner Radius', 'sb-customizer' ) }</strong>
                        <div
                            className='sb-cls-ctn sb-popupcontrol-popup-cls'
                            onClick={ () => {
                                editorControl.setTempControlPopup( null )
                                setBorderRadiusPopupActive( false )
                            }}
                        >
                        </div>
                    </div>
                    <div className='sb-popupcontrol-inputs sb-fs'>
                        <div className="sb-popupcontrol-1row">
                            <div className='sb-popupcontrol-inp-item'>
                                <span>{ __( 'Radius', 'sb-customizer' ) }</span>
                                <Input
                                    type='number'
                                    size='small'
                                    placeholder='4px'
                                    trailingText='px'
                                    disablebg='true'
                                    value={ borderRadiusValues.radius }
                                    onChange = { ( event ) =>
                                        changeBorderRadiusValue( event, 'radius' )
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
                                setBorderRadiusValues( initialValue )
                                props.onChange( initialValue );
                            } }
                        />
                    </div>

                </div>
            </div>
        </div>
    )
}

export default BorderRadius;