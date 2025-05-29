import { __ } from '@wordpress/i18n'
import SbUtils from '../../Utils/SbUtils'
import Button from './Button';
import Checkbox from './Checkbox';

const Source = ( props ) => {

    return (
        <div
            className={ 'sb-source-item ' + ( props?.customClass ? props?.customClass : '' ) }
            data-checked={props.isChecked}
            onClick={ ( event ) => {
                event.stopPropagation()
                event.preventDefault()
                if( props?.onClick )
                    props.onClick()
            }}
        >
            <div className='sb-source-item-top sb-fs'>
                {
                    ( props?.checkbox || props?.radiobox ) &&
                    <div className='sb-source-item-box'>
                        {
                            props.checkbox &&
                            <Checkbox
                                value={ props.isChecked }
                                enabled={ true }
                                onChange={ () => {
                                    if( props?.onClick )
                                        props.onClick()
                                }}
                            />
                        }
                        {
                            props.radiobox &&
                            <Checkbox
                                value={ props.isChecked }
                                enabled={ true }
                                onChange={ () => {
                                    if( props?.onClick )
                                        props.onClick()
                                }}
                            />
                        }
                    </div>
                }
                <div className='sb-source-item-icon'>
                    { SbUtils.printIcon( props.provider + '-provider', 'sb-source-svg' ) }
                </div>
                <div className='sb-source-item-info'>
                    <span className='sb-text-small sb-bold'>{ props.name }</span>
                    <span className='sb-dark2-text sb-text-tiny'>{ props.provider } { __('Reviews', 'sb-customizer') }</span>
                </div>
                {
                    ( props?.removeIcon || props?.infoIcon || (props?.needHistoryCheck && props?.needHistoryCheck === true) ) &&
                        <div className='sb-source-act-ctn'>
                            {
                                props?.needHistoryCheck && props?.needHistoryCheck === true &&
                                <div className='sb-source-item-needhist-icon sb-source-act-icon-ctn'>
                                    <div
                                        className='sb-source-act-icon sb-tr-2'
                                    >
                                        {
                                            SbUtils.printTooltip(
                                            __('Unable to retrieve reviews history for this source. Clear the cache or reconnect the source to try again.', 'sb-customizer'),
                                            {
                                                type : "white",
                                                position : 'top-center',
                                                replaceText : false
                                            } )
                                        }
                                        { SbUtils.printIcon( 'warning', false, false, 16  ) }
                                    </div>
                                </div>
                            }
                            {
                                props?.removeIcon &&
                                <div className='sb-source-item-remove-icon sb-source-act-icon-ctn'>
                                    <div
                                        className='sb-source-act-icon sb-tr-2'
                                        onClick={ () => {
                                            if( props?.onRemoveClick )
                                                props.onRemoveClick()
                                        }}
                                    >
                                        { SbUtils.printIcon( 'trash', false, false, 11  ) }
                                    </div>
                                </div>
                            }
                            {
                                props?.infoIcon &&
                                <div className='sb-source-item-info-icon sb-source-act-icon-ctn'>
                                    <div
                                        className='sb-source-act-icon sb-tr-2'
                                        onClick={ () => {
                                            props.onShowInfoClick()
                                        }}
                                    >
                                        { SbUtils.printIcon( 'cog', false, false, 15 ) }
                                    </div>
                                </div>
                            }
                        </div>
                }
            </div>
            {
                ( props?.infoIcon && props?.isCollapsed === true  ) &&
                <div className='sb-source-dp-info sb-fs'>
                    <div className='sb-source-dp-info-item'>
                        <strong>ID</strong>
                        <span>{ props.accountId }</span>
                        <Button
                            icon='copy'
                            type='secondary'
                            size='small'
                            boxshadow={false}
                            onClick={ () => {
                                SbUtils.copyToClipBoard( props.accountId, props.editorNotification )
                            } }
                        />
                    </div>
                </div>
            }
        </div>
    )
}

export default Source;