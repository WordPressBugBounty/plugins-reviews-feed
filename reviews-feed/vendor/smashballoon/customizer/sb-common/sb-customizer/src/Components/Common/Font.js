import { useState } from "react";
import { __ } from '@wordpress/i18n'
import SbUtils from '../../Utils/SbUtils'
import Input from './Input'
import Select from './Select'

const Font = ( props ) => {

    const parentActionsList =  [ 'onClick' ];
    const defaultValues = {
        family : 'inherit',
        weight : '400',
        size : '',
        height : '',
        ...props?.value
    };

    const [ fontValues,  setFontValues ] = useState( defaultValues );

    const changeFontValue = ( event, type ) => {
        const newFontValues = {
            ...fontValues,
            [ type ] : event.currentTarget.value
        };
        setFontValues( newFontValues );
        props.onChange( newFontValues );
    }


    return (
        <div
            className='sb-fontchooser-ctn'
            data-disabled={ props?.disabled === true}
            { ...SbUtils.getElementActions( props, parentActionsList ) }
        >
            {/*
            <div className='sb-fontchooser-row'>
                <span>{ __( 'Font', 'sb-customizer' ) }</span>
                <div className='sb-fontchooser-input'>
                    <Select
                        size='small'
                        value={ fontValues.family }
                        onChange={ ( event ) => {
                            changeFontValue( event, 'family' )
                        }}
                    >
                        <option>{ __( 'Inherit from Theme', 'sb-customizer' ) }</option>
                    </Select>
                </div>
            </div>
            */}
            <div className='sb-fontchooser-row'>
                <span>{ __( 'Weight', 'sb-customizer' ) }</span>
                <div className='sb-fontchooser-input'>
                    <Select
                        size='small'
                        value={ fontValues.weight }
                        disabled={ props?.disabled === true}
                        onChange={ ( event ) => {
                            changeFontValue( event, 'weight' )
                        }}
                    >
                        <option value='100'>100</option>
                        <option value='200'>200</option>
                        <option value='300'>300</option>
                        <option value='400'>{ __( '400 (Regular)', 'sb-customizer' ) }</option>
                        <option value='500'>500</option>
                        <option value='600'>600</option>
                        <option value='700'>700</option>
                        <option value='800'>800</option>
                        <option value='900'>900</option>
                    </Select>
                </div>
            </div>
            <div className='sb-fontchooser-row'>
                <span>{ __( 'Size', 'sb-customizer' ) }</span>
                <div className='sb-fontchooser-input'>
                    <Input
                        type='number'
                        size='small'
                        disablebg='true'
                        trailingText='px'
                        placeholder={ __( 'Inherit', 'sb-customizer' ) }
                        value={ fontValues.size }
                        disabled={ props?.disabled === true}
                        onChange={ ( event ) => {
                            changeFontValue( event, 'size' )
                        }}
                    />
                    <div className='sb-fontchooser-input'>
                        <span>{ __( 'Height', 'sb-customizer' ) }</span>
                        <Input
                            size='small'
                            disablebg='true'
                            trailingText='-'
                            placeholder={ __( 'Inherit', 'sb-customizer' ) }
                            value={ fontValues.height }
                            disabled={ props?.disabled === true}
                            onChange={ ( event ) => {
                                changeFontValue( event, 'height' )
                            }}
                        />
                    </div>
                </div>
            </div>

        </div>
    )
}

export default Font;