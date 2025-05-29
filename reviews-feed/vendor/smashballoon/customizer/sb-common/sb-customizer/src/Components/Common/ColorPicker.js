import { useState } from "react";
import { SketchPicker } from 'react-color';
import Button from './Button';
import SbUtils from '../../Utils/SbUtils'
import useOutsideClick from '../../Utils/useOutsideClick'


const ColorPicker = ( props ) => {
    const   [ colorPickerActive, setColorPickerActive ] = useState( false ),
            attrList = [
                {
                    'minimalist' : false
                },
            ],
            parentActionsList =  [ 'onClick'  ];

    const ref = useOutsideClick( () => {
       setColorPickerActive( false )
    } );

    const changeColorValue = ( color, event ) => {
        let colorValue = color.hex;
        if(color.rgb.a !== 1){
            colorValue = `rgba(${color.rgb.r}, ${color.rgb.g}, ${color.rgb.b}, ${color.rgb.a})`
        }
        props.onChange( colorValue )
    }

    const resetColor = () => {
        props.onChange( props?.default || '' )
    }

    return (
        <div
            ref={ ref }
            className='sb-colorpicker-ctn sb-fs'
            { ...SbUtils.getElementAttributes( props, attrList ) }
            data-active={ colorPickerActive }
            data-disabled={ props?.disabled }
            { ...SbUtils.getElementActions( props, parentActionsList ) }
        >
            <div
                className='sb-colorpicker-content sb-tr-2'
                onClick={ () => {
                    if( props?.disabled === false ){
                        setColorPickerActive( true )
                    }
                }}
            >
                <div className='sb-colorpicker-swatch' style={ { background: props?.value } }></div>
                {
                    !props.props &&
                    <div className='sb-colorpicker-value sb-text-tiny'>{ props?.value }</div>
                }
            </div>
            <div className='sb-colorpicker-popup sb-tr-1'>
                <SketchPicker
                    color={ props?.value }
                    onChange={ changeColorValue }
                />
                <Button
                    text='Reset'
                    size='small'
                    full-width='true'
                    type='secondary'
                    boxshadow='false'
                    customClass='sb-bold sb-text-tiny sb-dark-text'
                    onClick={ () => {
                        resetColor()
                    } }
                />
            </div>
        </div>
    )
}

export default ColorPicker;
