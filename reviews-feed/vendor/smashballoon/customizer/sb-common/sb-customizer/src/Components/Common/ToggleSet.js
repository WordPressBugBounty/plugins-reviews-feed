import SbUtils from '../../Utils/SbUtils'
const parse = require('html-react-parser');

const ToggleSet = ( props ) => {

    const slug = 'sb-toggleset',
          parentActionsList =  [ 'parentOnClick' ],
          elementsActionsList =  [ 'onClick' ],
          classesList =  [ 'size', 'layout' ];

    const onChooseElement = ( value ) => {
        props.onClick( value )
    }

    return (
        <div
            className={'sb-toggleset-ctn sb-fs ' + SbUtils.getClassNames( props, slug, classesList)}
            { ...SbUtils.getElementActions( props, parentActionsList ) }
        >
            {
                props?.options?.map( ( opt, optInd ) => {
                    const isSettingPro = SbUtils.checkSettingIsPro( opt.upsellModal );
                    return (
                        <div
                            className='sb-toggleset-elem sb-fs'
                            { ...SbUtils.getElementActions( props, elementsActionsList ) }
                            key={ optInd }
                            data-active={ props?.value === opt.value }
                            data-description={ opt.description !== undefined }
                            data-disabled={ isSettingPro !== false }
                            onClick={ () => {
                                if( isSettingPro === false ) {
                                    onChooseElement( opt.value )
                                }else{
                                    SbUtils.openUpsellModal( isSettingPro, props.upsellModal )
                                }
                            }}
                        >
                            <div className='sb-toggleset-elem-deco sb-tr-2'></div>
                            {
                                opt.icon &&
                                <div className='sb-toggleset-elem-icon'>{ SbUtils.printIcon( opt.icon ) }</div>
                            }
                            <div className='sb-toggleset-elem-label'>
                                <span className={(props.strongLabel ? 'sb-bold ' : '') + 'sb-fs'}>
                                    { parse(opt.label) }
                                    { opt.value === 'carousel' &&  SbUtils.printIcon('rocket-premium', 'pro-rocket-icon') }
                                </span>
                                {
                                    opt.description &&
                                    <span className='sb-toggleset-elem-description sb-text-tiny sb-dark2-text sb-fs'>{ parse(opt.description) }</span>
                                }
                            </div>

                        </div>
                    )
                })
            }

        </div>
    )
}

export default ToggleSet;