import SbUtils from '../../Utils/SbUtils'
import Input from './Input';
import { default as ReactSliderInput } from 'react-input-slider'


const Slider = ( props ) => {

    const slug              = 'sb-slider',
          isInputNumber     = props.inputNumber !== false,
          isLabel           = props.label !== undefined,
          isLabelIcon       = props.labelIcon !== undefined;

    const changeSliderValue = ( value ) => {
        props.onChange( value )
    }


    return (
        <div className='sb-slider-ctn sb-fs'>
            <div className='sb-slider-left'>
                {
                    ( isLabel || isLabelIcon ) &&
                    <div className='sb-control-elem-heading sb-text-tiny sb-fs'>
                        { props.labelIcon && <span className='sb-control-elem-icon'> { SbUtils.printIcon( props.labelIcon ) } </span> }
                        { props.label }
                    </div>
                }
                <div className='sb-slider-content sb-fs'>
                    <ReactSliderInput
                        axis='x'
                        xmin={ props.min ? props.min : 0 }
                        xmax={ props.max ? props.max : 100 }
                        x={ props?.value  }
                        onChange={ ( { x } ) =>
                            changeSliderValue( x )
                        }
                    />
                </div>
            </div>
            {
                isInputNumber &&
                <div className='sb-slider-input-ctn'>
                    <Input
                        type='number'
                        size='small'
                        min={ props.min ? props.min : 0 }
                        max={ props.max ? props.max : 100 }
                        trailingText={ props.unit }
                        name={ props?.name }
                        value={ props?.value }
                        onChange={ ( event ) => {
                            changeSliderValue( event.currentTarget.value  )
                        } }

                    />
                </div>
            }
        </div>
    )
}

export default Slider;