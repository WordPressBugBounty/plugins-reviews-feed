import { __ } from '@wordpress/i18n'
import SbUtils from '../../Utils/SbUtils'
import Button from '../Common/Button';
import ModalContainer from './ModalContainer';

const ConfirmDialog = ( props ) => {
    return (
        props?.active &&
        <ModalContainer
            size='small'
            closebutton={true}
            onClose={ () => {
                props?.onClose();
            } }
        >
            <div className='sb-confirm-modal sb-fs'>
                {
                    props?.heading &&
                    <strong className='sb-confirm-modal-heading sb-dark-text sb-fs'>{ props?.heading }</strong>
                }
                {
                    props?.description &&
                    <span className='sb-confirm-modal-description sb-text sb-dark2-text sb-fs'>{ props?.description }</span>
                }
                <div className='sb-confirm-modal-btns sb-fs'>
                    {
                        props?.confirm &&
                        <Button
                            text={ props?.confirm?.text ? props?.confirm?.text : __( 'Confirm', 'sb-customizer' ) }
                            size={ props?.confirm?.size ? props?.confirm?.size : 'medium' }
                            type={ props?.confirm?.type ? props?.confirm?.type : 'destructive' }
                            onClick={ () => {
                                props?.confirm?.onConfirm()
                                props?.onClose()
                            }}
                        />
                    }
                    {
                        (props?.cancel !== false || props?.cancel === undefined) &&
                        <Button
                            text={ props?.cancel?.text ? props?.cancel?.text : __( 'Cancel', 'sb-customizer' ) }
                            size={ props?.cancel?.size ? props?.cancel?.size : 'medium' }
                            type={ props?.cancel?.type ? props?.cancel?.type : 'secondary' }
                            onClick={ () => {
                                props?.onClose()
                                props?.cancel?.onCancel()
                            }}
                        />
                    }
                </div>
            </div>
        </ModalContainer>
    )
}

export default ConfirmDialog;