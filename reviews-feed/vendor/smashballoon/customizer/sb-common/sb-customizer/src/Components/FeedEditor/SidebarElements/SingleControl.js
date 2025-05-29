import SbUtils from '../../../Utils/SbUtils'

import ControlOutput from './ControlOutput';

const SingleControl = ( { control, dimmed } ) => {

    const attrList = [
        'type',
        'separator',
        {
            'layout' : 'block'
        },
        'reverse',
        'stacked',
        'child',
        'dimmed',
        'strongheading'
    ];
    /**
     * Detect Control Type
     * If "Input" Make some changes!
     *  */
    const inputControls = [
        'text',
        'url',
        'number',
        'date',
        'email',
        'month',
        'week',
        'tel'
    ];

    if( inputControls.includes( control.type ) ){
        control.inputType = control.type;
        control.type = 'input';
    }

    return (
        <div
            className='sb-control-elem-ctn sb-fs'
            { ...SbUtils.getElementAttributes( control, attrList ) }
            style={{ marginTop : control.top +'px', marginBottom : control.bottom +'px' }}
            data-id={control.id}
        >
            <div className='sb-control-content sb-fs'>
                {
                    ( control.heading || control.icon || control.description ) &&
                    <div className='sb-control-elem-info'>
                        {
                            control.heading &&
                            <span className={ 'sb-control-elem-heading ' + ( !control.strongheading ? 'sb-text-tiny' : 'sb-text-small') } >
                                { control.icon && <div className='sb-control-elem-icon'> { SbUtils.printIcon( control.icon ) } </div> }
                                { control.heading }
                            </span>
                        }
                        {
                            control.description &&
                            <span className='sb-control-elem-description'>{ control.description }</span>
                        }
                    </div>
                }
                <div className='sb-control-elem-output'>
                    <ControlOutput
                        control={ control }
                    />
                </div>
                {
                    control.bottomDescription &&
                    <div className='sb-control-elem-description'>{ control.bottomDescription }</div>
                }
            </div>
            {
                control?.labelDescription &&
                 <span className='sb-el-label-description sb-small-p sb-text-tiny sb-fs'>{ control.labelDescription }</span>
            }

        </div>
    )
}

export default SingleControl;